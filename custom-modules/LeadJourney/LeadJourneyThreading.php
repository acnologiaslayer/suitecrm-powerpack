<?php
/**
 * LeadJourney Threading
 *
 * Groups timeline items into conversations based on:
 * - Email threads (grouped by subject/In-Reply-To)
 * - Call/SMS conversations (grouped by time proximity - 1 hour window)
 */

if (!defined('sugarEntry') || !sugarEntry) die('Not A Valid Entry Point');

class LeadJourneyThreading
{
    /**
     * Time window in seconds for grouping calls/SMS into conversations
     */
    const TIME_PROXIMITY_WINDOW = 3600; // 1 hour

    /**
     * Group timeline items into threaded conversations
     *
     * @param array $timeline Flat timeline items
     * @return array Grouped timeline with thread information
     */
    public static function groupIntoThreads($timeline)
    {
        if (empty($timeline)) {
            return [];
        }

        // Separate items by type for different grouping logic
        $emails = [];
        $calls = [];
        $sms = [];
        $other = [];

        foreach ($timeline as $item) {
            switch ($item['type']) {
                case 'email':
                case 'inbound_email':
                    $emails[] = $item;
                    break;
                case 'call':
                case 'inbound_call':
                case 'outbound_call':
                case 'voicemail':
                    $calls[] = $item;
                    break;
                case 'sms':
                case 'inbound_sms':
                case 'outbound_sms':
                    $sms[] = $item;
                    break;
                default:
                    $other[] = $item;
            }
        }

        // Group each type
        $groupedEmails = self::groupEmailsBySubject($emails);
        $groupedCalls = self::groupByTimeProximity($calls, 'Call Conversation');
        $groupedSms = self::groupByTimeProximity($sms, 'SMS Conversation');

        // Merge all groups and other items
        $threaded = array_merge($groupedEmails, $groupedCalls, $groupedSms);

        // Add non-grouped items
        foreach ($other as $item) {
            $threaded[] = [
                'thread_id' => $item['id'],
                'thread_name' => $item['title'],
                'thread_type' => $item['type'],
                'first_date' => $item['date'],
                'last_date' => $item['date'],
                'item_count' => 1,
                'items' => [$item],
                'is_group' => false
            ];
        }

        // Sort threads by most recent activity
        usort($threaded, function($a, $b) {
            return strtotime($b['last_date']) - strtotime($a['last_date']);
        });

        return $threaded;
    }

    /**
     * Group emails by normalized subject (removing Re:, Fwd:, etc.)
     */
    private static function groupEmailsBySubject($emails)
    {
        if (empty($emails)) {
            return [];
        }

        $threads = [];

        foreach ($emails as $email) {
            $normalizedSubject = self::normalizeEmailSubject($email['title']);
            $threadKey = md5(strtolower($normalizedSubject));

            if (!isset($threads[$threadKey])) {
                $threads[$threadKey] = [
                    'thread_id' => 'email_' . $threadKey,
                    'thread_name' => $normalizedSubject ?: 'Email Conversation',
                    'thread_type' => 'email_thread',
                    'first_date' => $email['date'],
                    'last_date' => $email['date'],
                    'item_count' => 0,
                    'items' => [],
                    'is_group' => true
                ];
            }

            $threads[$threadKey]['items'][] = $email;
            $threads[$threadKey]['item_count']++;

            // Update date range
            if (strtotime($email['date']) < strtotime($threads[$threadKey]['first_date'])) {
                $threads[$threadKey]['first_date'] = $email['date'];
            }
            if (strtotime($email['date']) > strtotime($threads[$threadKey]['last_date'])) {
                $threads[$threadKey]['last_date'] = $email['date'];
            }
        }

        // Sort items within each thread by date (oldest first for reading order)
        foreach ($threads as &$thread) {
            usort($thread['items'], function($a, $b) {
                return strtotime($a['date']) - strtotime($b['date']);
            });

            // If only one item, don't treat as group
            if ($thread['item_count'] === 1) {
                $thread['is_group'] = false;
            }
        }

        return array_values($threads);
    }

    /**
     * Group items by time proximity (for calls/SMS)
     */
    private static function groupByTimeProximity($items, $groupNamePrefix)
    {
        if (empty($items)) {
            return [];
        }

        // Sort by date ascending for grouping
        usort($items, function($a, $b) {
            return strtotime($a['date']) - strtotime($b['date']);
        });

        $threads = [];
        $currentThread = null;
        $threadIndex = 0;

        foreach ($items as $item) {
            $itemTime = strtotime($item['date']);

            // Check if this item belongs to current thread
            if ($currentThread !== null) {
                $lastItemTime = strtotime($currentThread['last_date']);
                $timeDiff = $itemTime - $lastItemTime;

                if ($timeDiff <= self::TIME_PROXIMITY_WINDOW) {
                    // Add to current thread
                    $currentThread['items'][] = $item;
                    $currentThread['item_count']++;
                    $currentThread['last_date'] = $item['date'];
                    continue;
                }
            }

            // Start new thread
            if ($currentThread !== null) {
                // Finalize previous thread
                if ($currentThread['item_count'] === 1) {
                    $currentThread['is_group'] = false;
                    $currentThread['thread_name'] = $currentThread['items'][0]['title'];
                }
                $threads[] = $currentThread;
            }

            $threadIndex++;
            $currentThread = [
                'thread_id' => strtolower(str_replace(' ', '_', $groupNamePrefix)) . '_' . $threadIndex . '_' . date('Ymd', $itemTime),
                'thread_name' => $groupNamePrefix . ' - ' . date('M j, Y', $itemTime),
                'thread_type' => $item['type'] . '_thread',
                'first_date' => $item['date'],
                'last_date' => $item['date'],
                'item_count' => 1,
                'items' => [$item],
                'is_group' => true
            ];
        }

        // Add last thread
        if ($currentThread !== null) {
            if ($currentThread['item_count'] === 1) {
                $currentThread['is_group'] = false;
                $currentThread['thread_name'] = $currentThread['items'][0]['title'];
            }
            $threads[] = $currentThread;
        }

        return $threads;
    }

    /**
     * Normalize email subject by removing Re:, Fwd:, etc.
     */
    private static function normalizeEmailSubject($subject)
    {
        // Remove common prefixes
        $patterns = [
            '/^(re|fw|fwd|aw|wg|sv|vs|antw):\s*/i',  // Common reply/forward prefixes
            '/^\[.*?\]\s*/',                          // Mailing list tags like [LIST]
        ];

        $normalized = $subject;

        // Keep removing prefixes until no more are found
        $maxIterations = 10;
        $iteration = 0;

        do {
            $previous = $normalized;
            foreach ($patterns as $pattern) {
                $normalized = preg_replace($pattern, '', $normalized);
            }
            $normalized = trim($normalized);
            $iteration++;
        } while ($normalized !== $previous && $iteration < $maxIterations);

        return $normalized;
    }

    /**
     * Generate a thread ID for a new item based on existing conversations
     * Used when logging new touchpoints
     */
    public static function findOrCreateThreadId($parentType, $parentId, $itemType, $itemData)
    {
        global $db;

        // For emails, try to find existing thread by subject
        if (in_array($itemType, ['email', 'inbound_email'])) {
            $subject = $itemData['subject'] ?? $itemData['title'] ?? '';
            $normalizedSubject = self::normalizeEmailSubject($subject);

            if (!empty($normalizedSubject)) {
                // Look for existing email thread
                $sql = "SELECT thread_id FROM lead_journey
                        WHERE parent_type = " . $db->quoted($parentType) . "
                        AND parent_id = " . $db->quoted($parentId) . "
                        AND touchpoint_type IN ('email', 'inbound_email')
                        AND thread_id IS NOT NULL
                        AND thread_id != ''
                        AND deleted = 0
                        ORDER BY touchpoint_date DESC
                        LIMIT 50";

                $result = $db->query($sql);
                while ($row = $db->fetchByAssoc($result)) {
                    // Get the thread's subject
                    $threadSql = "SELECT name FROM lead_journey
                                  WHERE thread_id = " . $db->quoted($row['thread_id']) . "
                                  AND deleted = 0
                                  ORDER BY touchpoint_date ASC
                                  LIMIT 1";
                    $threadResult = $db->query($threadSql);
                    if ($threadRow = $db->fetchByAssoc($threadResult)) {
                        $existingSubject = self::normalizeEmailSubject($threadRow['name']);
                        if (strtolower($existingSubject) === strtolower($normalizedSubject)) {
                            return $row['thread_id'];
                        }
                    }
                }

                // No existing thread found, create new ID
                return 'email_' . md5(strtolower($normalizedSubject));
            }
        }

        // For calls/SMS, check for recent activity within time window
        if (in_array($itemType, ['call', 'sms', 'inbound_call', 'outbound_call', 'inbound_sms', 'outbound_sms', 'voicemail'])) {
            $typeGroup = in_array($itemType, ['call', 'inbound_call', 'outbound_call', 'voicemail']) ?
                         ['call', 'inbound_call', 'outbound_call', 'voicemail'] :
                         ['sms', 'inbound_sms', 'outbound_sms'];

            $typeList = implode("','", $typeGroup);
            $windowStart = date('Y-m-d H:i:s', time() - self::TIME_PROXIMITY_WINDOW);

            $sql = "SELECT thread_id FROM lead_journey
                    WHERE parent_type = " . $db->quoted($parentType) . "
                    AND parent_id = " . $db->quoted($parentId) . "
                    AND touchpoint_type IN ('$typeList')
                    AND touchpoint_date >= " . $db->quoted($windowStart) . "
                    AND thread_id IS NOT NULL
                    AND thread_id != ''
                    AND deleted = 0
                    ORDER BY touchpoint_date DESC
                    LIMIT 1";

            $result = $db->query($sql);
            if ($row = $db->fetchByAssoc($result)) {
                return $row['thread_id'];
            }

            // Create new thread ID
            $prefix = in_array($itemType, ['call', 'inbound_call', 'outbound_call', 'voicemail']) ? 'call' : 'sms';
            return $prefix . '_conversation_' . date('YmdHis') . '_' . substr(create_guid(), 0, 8);
        }

        // Default: unique thread ID for this item
        return $itemType . '_' . create_guid();
    }

    /**
     * Get thread summary statistics
     */
    public static function getThreadStats($parentType, $parentId)
    {
        global $db;

        $sql = "SELECT
                    COUNT(DISTINCT thread_id) as total_threads,
                    COUNT(*) as total_items,
                    SUM(CASE WHEN touchpoint_type IN ('email', 'inbound_email') THEN 1 ELSE 0 END) as email_count,
                    SUM(CASE WHEN touchpoint_type IN ('call', 'inbound_call', 'outbound_call', 'voicemail') THEN 1 ELSE 0 END) as call_count,
                    SUM(CASE WHEN touchpoint_type IN ('sms', 'inbound_sms', 'outbound_sms') THEN 1 ELSE 0 END) as sms_count
                FROM lead_journey
                WHERE parent_type = " . $db->quoted($parentType) . "
                AND parent_id = " . $db->quoted($parentId) . "
                AND deleted = 0";

        $result = $db->query($sql);
        return $db->fetchByAssoc($result) ?: [
            'total_threads' => 0,
            'total_items' => 0,
            'email_count' => 0,
            'call_count' => 0,
            'sms_count' => 0
        ];
    }
}
