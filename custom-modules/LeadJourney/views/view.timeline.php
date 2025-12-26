<?php
require_once('include/MVC/View/SugarView.php');
require_once('modules/ACLActions/ACLAction.php');
require_once('modules/LeadJourney/LeadJourneyThreading.php');

class LeadJourneyViewTimeline extends SugarView {
    private $canViewRecordings = false;
    private $viewMode = 'flat'; // 'flat' or 'threaded'

    public function display() {
        global $current_user;

        $parentType = $_GET['parent_type'] ?? 'Leads';
        $parentId = $_GET['parent_id'] ?? '';
        $this->viewMode = $_GET['view_mode'] ?? 'flat';

        if (empty($parentId)) {
            echo '<div class="alert alert-warning">No record specified</div>';
            return;
        }

        // Get the parent record
        $parent = BeanFactory::getBean($parentType, $parentId);

        if (!$parent) {
            echo '<div class="alert alert-danger">Record not found</div>';
            return;
        }

        // Check if user can view recordings
        $this->canViewRecordings = $this->checkRecordingPermission();

        // Get timeline data based on view mode
        if ($this->viewMode === 'threaded') {
            $timeline = LeadJourney::getThreadedTimeline($parentType, $parentId);
            echo $this->renderThreadedTimeline($parent, $timeline, $parentType, $parentId);
        } else {
            $timeline = LeadJourney::getJourneyTimeline($parentType, $parentId);
            echo $this->renderFlatTimeline($parent, $timeline, $parentType, $parentId);
        }
    }

    /**
     * Check if current user has permission to view recordings
     */
    private function checkRecordingPermission() {
        global $current_user;

        if (empty($current_user) || empty($current_user->id)) {
            return false;
        }

        // Admins always have access
        if ($current_user->isAdmin()) {
            return true;
        }

        // Check ACL action
        $access = ACLAction::getUserAccessLevel($current_user->id, 'TwilioIntegration', 'view_recordings');
        if ($access >= 90) {
            return true;
        }

        // Check role-based access
        $db = DBManagerFactory::getInstance();
        $userId = $db->quote($current_user->id);

        $sql = "SELECT ara.access
                FROM acl_roles_users aru
                JOIN acl_roles_actions ara ON aru.role_id = ara.role_id
                JOIN acl_actions aa ON ara.action_id = aa.id
                WHERE aru.user_id = '$userId'
                AND aru.deleted = 0
                AND ara.deleted = 0
                AND aa.category = 'TwilioIntegration'
                AND aa.name = 'view_recordings'
                AND aa.deleted = 0
                AND ara.access >= 90
                LIMIT 1";

        $result = $db->query($sql);
        return (bool)$db->fetchByAssoc($result);
    }

    /**
     * Render the common styles
     */
    private function renderStyles() {
        ob_start();
        ?>
        <style>
            .journey-timeline {
                max-width: 1200px;
                margin: 20px auto;
                padding: 20px;
                background: #fff;
            }
            .timeline-header {
                border-bottom: 2px solid #0070d2;
                padding-bottom: 20px;
                margin-bottom: 30px;
            }
            .timeline-header h2 {
                margin: 0 0 10px 0;
                color: #333;
            }
            .timeline-stats {
                display: flex;
                gap: 30px;
                margin-top: 15px;
                flex-wrap: wrap;
            }
            .stat-box {
                padding: 10px 20px;
                background: #f4f6f9;
                border-radius: 4px;
            }
            .stat-box .label {
                font-size: 12px;
                color: #666;
                text-transform: uppercase;
            }
            .stat-box .value {
                font-size: 24px;
                font-weight: bold;
                color: #0070d2;
            }
            .timeline-controls {
                display: flex;
                justify-content: space-between;
                align-items: center;
                margin-bottom: 20px;
                flex-wrap: wrap;
                gap: 15px;
            }
            .view-toggle {
                display: flex;
                gap: 5px;
                background: #f4f6f9;
                padding: 5px;
                border-radius: 6px;
            }
            .view-toggle-btn {
                padding: 8px 16px;
                border: none;
                background: transparent;
                cursor: pointer;
                border-radius: 4px;
                font-size: 14px;
                transition: all 0.2s;
            }
            .view-toggle-btn.active {
                background: #0070d2;
                color: white;
            }
            .view-toggle-btn:hover:not(.active) {
                background: #e0e5e9;
            }
            .filter-buttons {
                display: flex;
                flex-wrap: wrap;
                gap: 8px;
            }
            .filter-btn {
                padding: 8px 16px;
                border: 1px solid #ddd;
                background: white;
                cursor: pointer;
                border-radius: 4px;
                font-size: 13px;
                transition: all 0.2s;
            }
            .filter-btn.active {
                background: #0070d2;
                color: white;
                border-color: #0070d2;
            }
            .filter-btn:hover:not(.active) {
                background: #f4f6f9;
            }
            .timeline-list {
                position: relative;
                padding-left: 40px;
            }
            .timeline-list::before {
                content: '';
                position: absolute;
                left: 15px;
                top: 0;
                bottom: 0;
                width: 2px;
                background: #e0e0e0;
            }
            .timeline-item {
                position: relative;
                margin-bottom: 20px;
                padding: 15px;
                background: #f9f9f9;
                border-radius: 8px;
                border-left: 4px solid #0070d2;
            }
            .timeline-item::before {
                content: '';
                position: absolute;
                left: -29px;
                top: 20px;
                width: 12px;
                height: 12px;
                border-radius: 50%;
                background: #0070d2;
                border: 3px solid #fff;
            }
            .timeline-item.call::before, .timeline-item.inbound_call::before, .timeline-item.outbound_call::before { background: #34a853; }
            .timeline-item.email::before, .timeline-item.inbound_email::before { background: #fbbc04; }
            .timeline-item.meeting::before { background: #ea4335; }
            .timeline-item.site_visit::before { background: #4285f4; }
            .timeline-item.linkedin_click::before { background: #0077b5; }
            .timeline-item.campaign::before { background: #9c27b0; }
            .timeline-item.sms::before, .timeline-item.inbound_sms::before, .timeline-item.outbound_sms::before { background: #00bcd4; }
            .timeline-item.voicemail::before { background: #ff9800; }

            .timeline-icon {
                display: inline-block;
                width: 30px;
                height: 30px;
                margin-right: 10px;
                text-align: center;
                line-height: 30px;
                border-radius: 50%;
                background: #0070d2;
                color: white;
            }
            .timeline-title {
                font-size: 16px;
                font-weight: bold;
                margin-bottom: 5px;
            }
            .timeline-date {
                font-size: 12px;
                color: #666;
                margin-bottom: 10px;
            }
            .timeline-description {
                color: #555;
                line-height: 1.5;
            }
            .timeline-meta {
                margin-top: 10px;
                font-size: 12px;
                color: #888;
            }

            /* Thread-specific styles */
            .thread-group {
                margin-bottom: 25px;
                background: #fff;
                border: 1px solid #e0e5e9;
                border-radius: 8px;
                overflow: hidden;
            }
            .thread-header {
                padding: 15px 20px;
                background: #f4f6f9;
                border-bottom: 1px solid #e0e5e9;
                cursor: pointer;
                display: flex;
                justify-content: space-between;
                align-items: center;
            }
            .thread-header:hover {
                background: #e8ecf0;
            }
            .thread-title {
                font-weight: bold;
                font-size: 15px;
                color: #333;
            }
            .thread-meta {
                font-size: 12px;
                color: #666;
                display: flex;
                gap: 15px;
                align-items: center;
            }
            .thread-badge {
                background: #0070d2;
                color: white;
                padding: 3px 10px;
                border-radius: 12px;
                font-size: 11px;
            }
            .thread-items {
                padding: 10px 0;
                display: none;
            }
            .thread-items.expanded {
                display: block;
            }
            .thread-items .timeline-item {
                margin: 10px 20px;
                border-left-width: 3px;
            }
            .thread-expand-icon {
                transition: transform 0.2s;
            }
            .thread-expand-icon.expanded {
                transform: rotate(180deg);
            }
            .single-item .thread-items {
                display: block;
            }
            .single-item .thread-header {
                cursor: default;
            }
            .single-item .thread-expand-icon {
                display: none;
            }
        </style>
        <?php
        return ob_get_clean();
    }

    /**
     * Render flat (traditional) timeline view
     */
    private function renderFlatTimeline($parent, $timeline, $parentType, $parentId) {
        ob_start();
        echo $this->renderStyles();
        ?>
        <div class="journey-timeline">
            <div class="timeline-header">
                <h2>Journey Timeline: <?php echo htmlspecialchars($parent->name); ?></h2>
                <div class="timeline-stats">
                    <div class="stat-box">
                        <div class="label">Total Touchpoints</div>
                        <div class="value"><?php echo count($timeline); ?></div>
                    </div>
                    <div class="stat-box">
                        <div class="label">Calls</div>
                        <div class="value"><?php echo $this->countByType($timeline, ['call', 'inbound_call', 'outbound_call']); ?></div>
                    </div>
                    <div class="stat-box">
                        <div class="label">Emails</div>
                        <div class="value"><?php echo $this->countByType($timeline, ['email', 'inbound_email']); ?></div>
                    </div>
                    <div class="stat-box">
                        <div class="label">SMS</div>
                        <div class="value"><?php echo $this->countByType($timeline, ['sms', 'inbound_sms', 'outbound_sms']); ?></div>
                    </div>
                </div>
            </div>

            <div class="timeline-controls">
                <div class="view-toggle">
                    <button class="view-toggle-btn active" onclick="switchView('flat', '<?php echo $parentType; ?>', '<?php echo $parentId; ?>')">
                        Flat View
                    </button>
                    <button class="view-toggle-btn" onclick="switchView('threaded', '<?php echo $parentType; ?>', '<?php echo $parentId; ?>')">
                        Conversations
                    </button>
                </div>
                <div class="filter-buttons">
                    <button class="filter-btn active" data-filter="all">All</button>
                    <button class="filter-btn" data-filter="call">Calls</button>
                    <button class="filter-btn" data-filter="email">Emails</button>
                    <button class="filter-btn" data-filter="sms">SMS</button>
                    <button class="filter-btn" data-filter="meeting">Meetings</button>
                    <button class="filter-btn" data-filter="site_visit">Site Visits</button>
                </div>
            </div>

            <div class="timeline-list">
                <?php foreach ($timeline as $item): ?>
                    <?php echo $this->renderTimelineItem($item); ?>
                <?php endforeach; ?>

                <?php if (empty($timeline)): ?>
                    <div style="text-align: center; padding: 40px; color: #666;">
                        No activities found for this record.
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <?php echo $this->renderScripts(); ?>
        <?php
        return ob_get_clean();
    }

    /**
     * Render threaded conversation view
     */
    private function renderThreadedTimeline($parent, $threads, $parentType, $parentId) {
        ob_start();
        echo $this->renderStyles();

        $totalItems = 0;
        foreach ($threads as $thread) {
            $totalItems += $thread['item_count'];
        }
        ?>
        <div class="journey-timeline">
            <div class="timeline-header">
                <h2>Journey Timeline: <?php echo htmlspecialchars($parent->name); ?></h2>
                <div class="timeline-stats">
                    <div class="stat-box">
                        <div class="label">Conversations</div>
                        <div class="value"><?php echo count($threads); ?></div>
                    </div>
                    <div class="stat-box">
                        <div class="label">Total Activities</div>
                        <div class="value"><?php echo $totalItems; ?></div>
                    </div>
                </div>
            </div>

            <div class="timeline-controls">
                <div class="view-toggle">
                    <button class="view-toggle-btn" onclick="switchView('flat', '<?php echo $parentType; ?>', '<?php echo $parentId; ?>')">
                        Flat View
                    </button>
                    <button class="view-toggle-btn active" onclick="switchView('threaded', '<?php echo $parentType; ?>', '<?php echo $parentId; ?>')">
                        Conversations
                    </button>
                </div>
                <div>
                    <button class="filter-btn" onclick="expandAllThreads()">Expand All</button>
                    <button class="filter-btn" onclick="collapseAllThreads()">Collapse All</button>
                </div>
            </div>

            <div class="thread-list">
                <?php foreach ($threads as $thread): ?>
                    <div class="thread-group <?php echo $thread['is_group'] ? '' : 'single-item'; ?>" data-thread-type="<?php echo htmlspecialchars($thread['thread_type']); ?>">
                        <div class="thread-header" onclick="toggleThread(this)">
                            <div>
                                <div class="thread-title">
                                    <?php echo $this->getThreadIcon($thread['thread_type']); ?>
                                    <?php echo htmlspecialchars($thread['thread_name']); ?>
                                </div>
                                <div class="thread-meta">
                                    <span><?php echo date('M j, Y g:i A', strtotime($thread['last_date'])); ?></span>
                                    <?php if ($thread['is_group']): ?>
                                        <span class="thread-badge"><?php echo $thread['item_count']; ?> items</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <span class="thread-expand-icon">&#9660;</span>
                        </div>
                        <div class="thread-items">
                            <?php foreach ($thread['items'] as $item): ?>
                                <?php echo $this->renderTimelineItem($item); ?>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endforeach; ?>

                <?php if (empty($threads)): ?>
                    <div style="text-align: center; padding: 40px; color: #666;">
                        No conversations found for this record.
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <?php echo $this->renderScripts(); ?>
        <script>
            function toggleThread(header) {
                const group = header.closest('.thread-group');
                if (group.classList.contains('single-item')) return;

                const items = group.querySelector('.thread-items');
                const icon = group.querySelector('.thread-expand-icon');

                items.classList.toggle('expanded');
                icon.classList.toggle('expanded');
            }

            function expandAllThreads() {
                document.querySelectorAll('.thread-group:not(.single-item) .thread-items').forEach(el => {
                    el.classList.add('expanded');
                });
                document.querySelectorAll('.thread-group:not(.single-item) .thread-expand-icon').forEach(el => {
                    el.classList.add('expanded');
                });
            }

            function collapseAllThreads() {
                document.querySelectorAll('.thread-group:not(.single-item) .thread-items').forEach(el => {
                    el.classList.remove('expanded');
                });
                document.querySelectorAll('.thread-group:not(.single-item) .thread-expand-icon').forEach(el => {
                    el.classList.remove('expanded');
                });
            }
        </script>
        <?php
        return ob_get_clean();
    }

    /**
     * Render a single timeline item
     */
    private function renderTimelineItem($item) {
        ob_start();
        $type = $item['type'];
        // Normalize type for CSS class
        $cssType = str_replace(['inbound_', 'outbound_'], '', $type);
        ?>
        <div class="timeline-item <?php echo htmlspecialchars($cssType); ?>" data-type="<?php echo htmlspecialchars($cssType); ?>">
            <div class="timeline-icon">
                <?php echo $this->getIcon($item['icon'] ?? 'circle'); ?>
            </div>
            <div class="timeline-title"><?php echo htmlspecialchars($item['title']); ?></div>
            <div class="timeline-date"><?php echo date('F j, Y g:i A', strtotime($item['date'])); ?></div>
            <div class="timeline-description"><?php echo htmlspecialchars($item['description'] ?? ''); ?></div>
            <div class="timeline-meta">
                <?php if (isset($item['duration']) && $item['duration'] > 0): ?>
                    Duration: <?php echo $item['duration']; ?> minutes
                <?php endif; ?>
                <?php if (isset($item['status'])): ?>
                    | Status: <?php echo htmlspecialchars($item['status']); ?>
                <?php endif; ?>
                <?php if (isset($item['direction'])): ?>
                    | Direction: <?php echo htmlspecialchars($item['direction']); ?>
                <?php endif; ?>
                <?php
                // Show recording link if available and user has permission
                $recordingUrl = $this->getRecordingUrl($item);
                if ($recordingUrl && $this->canViewRecordings): ?>
                    <div class="recording-link" style="margin-top: 8px;">
                        <audio controls style="height: 30px; width: 100%; max-width: 300px;">
                            <source src="<?php echo htmlspecialchars($recordingUrl); ?>" type="audio/mpeg">
                            Your browser does not support the audio element.
                        </audio>
                        <a href="<?php echo htmlspecialchars($recordingUrl); ?>" class="btn btn-sm btn-outline-primary" download style="margin-left: 10px;">Download</a>
                    </div>
                <?php elseif ($recordingUrl && !$this->canViewRecordings): ?>
                    <div class="recording-restricted" style="margin-top: 8px; color: #999; font-style: italic;">
                        Recording available (permission required)
                    </div>
                <?php endif; ?>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Render common scripts
     */
    private function renderScripts() {
        ob_start();
        ?>
        <script>
            function switchView(mode, parentType, parentId) {
                window.location.href = 'index.php?module=LeadJourney&action=timeline&parent_type=' +
                    encodeURIComponent(parentType) + '&parent_id=' + encodeURIComponent(parentId) +
                    '&view_mode=' + mode;
            }

            // Filter functionality
            document.querySelectorAll('.filter-btn[data-filter]').forEach(btn => {
                btn.addEventListener('click', function() {
                    const filter = this.dataset.filter;

                    // Update active button
                    document.querySelectorAll('.filter-btn[data-filter]').forEach(b => b.classList.remove('active'));
                    this.classList.add('active');

                    // Filter timeline items
                    document.querySelectorAll('.timeline-item').forEach(item => {
                        const itemType = item.dataset.type;
                        if (filter === 'all' || itemType === filter ||
                            (filter === 'call' && ['call', 'inbound_call', 'outbound_call', 'voicemail'].includes(itemType)) ||
                            (filter === 'email' && ['email', 'inbound_email'].includes(itemType)) ||
                            (filter === 'sms' && ['sms', 'inbound_sms', 'outbound_sms'].includes(itemType))) {
                            item.style.display = 'block';
                        } else {
                            item.style.display = 'none';
                        }
                    });
                });
            });
        </script>
        <?php
        return ob_get_clean();
    }

    private function countByType($timeline, $types) {
        if (!is_array($types)) {
            $types = [$types];
        }
        return count(array_filter($timeline, function($item) use ($types) {
            return in_array($item['type'], $types);
        }));
    }

    private function getIcon($icon) {
        $icons = array(
            'phone' => '&#128222;',
            'envelope' => '&#9993;',
            'calendar' => '&#128197;',
            'globe' => '&#127760;',
            'linkedin' => '&#128188;',
            'bullhorn' => '&#128226;',
            'voicemail' => '&#128252;',
            'sms' => '&#128172;',
            'circle' => '&#9679;',
        );
        return $icons[$icon] ?? '&#8226;';
    }

    private function getThreadIcon($threadType) {
        $icons = [
            'email_thread' => '&#9993;',
            'call_thread' => '&#128222;',
            'sms_thread' => '&#128172;',
        ];

        foreach ($icons as $type => $icon) {
            if (strpos($threadType, str_replace('_thread', '', $type)) !== false) {
                return $icon;
            }
        }
        return '&#8226;';
    }

    /**
     * Get recording URL from timeline item data
     */
    private function getRecordingUrl($item) {
        // Check for recording in extra data
        if (!empty($item['recording_url'])) {
            return $item['recording_url'];
        }

        // Check in touchpoint_data
        if (!empty($item['touchpoint_data'])) {
            $data = is_array($item['touchpoint_data']) ? $item['touchpoint_data'] : json_decode($item['touchpoint_data'], true);
            if (!empty($data['recording_url'])) {
                return $data['recording_url'];
            }

            // Build URL from recording_sid if available
            if (!empty($data['recording_sid'])) {
                return 'index.php?module=TwilioIntegration&action=recording&recording_id=' . urlencode($data['recording_sid']);
            }

            // Build URL from document_id if available
            if (!empty($data['document_id'])) {
                return 'index.php?module=TwilioIntegration&action=recording&document_id=' . urlencode($data['document_id']);
            }
        }

        return null;
    }
}
