<?php
if(!defined('sugarEntry') || !sugarEntry) die('Not A Valid Entry Point');

class LeadJourney extends Basic {
    public $new_schema = true;
    public $module_dir = 'LeadJourney';
    public $object_name = 'LeadJourney';
    public $table_name = 'lead_journey';
    public $importable = false;
    
    public $id;
    public $name;
    public $date_entered;
    public $date_modified;
    public $modified_user_id;
    public $modified_by_name;
    public $created_by;
    public $created_by_name;
    public $description;
    public $deleted;
    
    // Journey specific fields
    public $parent_type;
    public $parent_id;
    public $touchpoint_type; // call, email, meeting, site_visit, linkedin_click, etc.
    public $touchpoint_date;
    public $touchpoint_data; // JSON field for additional data
    public $source;
    public $campaign_id;
    public $thread_id; // For conversation threading
    public $recording_url; // For call recordings

    public function __construct() {
        parent::__construct();
    }

    public function bean_implements($interface){
        switch($interface){
            case 'ACL': return true;
        }
        return false;
    }
    
    /**
     * Get complete journey timeline for a lead/contact
     */
    public static function getJourneyTimeline($parentType, $parentId) {
        global $db;
        
        $timeline = array();
        
        // Get Calls
        $calls = self::getCalls($parentType, $parentId);
        $timeline = array_merge($timeline, $calls);
        
        // Get Emails
        $emails = self::getEmails($parentType, $parentId);
        $timeline = array_merge($timeline, $emails);
        
        // Get Meetings
        $meetings = self::getMeetings($parentType, $parentId);
        $timeline = array_merge($timeline, $meetings);
        
        // Get Site Visits
        $visits = self::getSiteVisits($parentType, $parentId);
        $timeline = array_merge($timeline, $visits);
        
        // Get LinkedIn Clicks
        $linkedin = self::getLinkedInClicks($parentType, $parentId);
        $timeline = array_merge($timeline, $linkedin);
        
        // Get Campaign Responses
        $campaigns = self::getCampaignResponses($parentType, $parentId);
        $timeline = array_merge($timeline, $campaigns);

        // Get Custom Touchpoints (from lead_journey table)
        $customTouchpoints = self::getCustomTouchpoints($parentType, $parentId);
        $timeline = array_merge($timeline, $customTouchpoints);

        // Sort by date descending
        usort($timeline, function($a, $b) {
            return strtotime($b['date']) - strtotime($a['date']);
        });

        return $timeline;
    }
    
    /**
     * Get all calls for a record
     */
    private static function getCalls($parentType, $parentId) {
        global $db;
        
        $query = "SELECT c.id, c.name, c.date_start, c.duration_hours, c.duration_minutes, 
                         c.status, c.direction, c.description
                  FROM calls c
                  WHERE c.parent_type = " . $db->quoted($parentType) . "
                  AND c.parent_id = " . $db->quoted($parentId) . "
                  AND c.deleted = 0
                  ORDER BY c.date_start DESC";
        
        $result = $db->query($query);
        $calls = array();
        
        while ($row = $db->fetchByAssoc($result)) {
            $calls[] = array(
                'id' => $row['id'],
                'type' => 'call',
                'icon' => 'phone',
                'title' => $row['name'],
                'date' => $row['date_start'],
                'status' => $row['status'],
                'direction' => $row['direction'],
                'duration' => ($row['duration_hours'] * 60) + $row['duration_minutes'],
                'description' => $row['description'],
            );
        }
        
        return $calls;
    }
    
    /**
     * Get all emails for a record
     */
    private static function getEmails($parentType, $parentId) {
        global $db;
        
        $query = "SELECT e.id, e.name, e.date_sent, e.status, e.description_html
                  FROM emails e
                  JOIN emails_beans eb ON e.id = eb.email_id
                  WHERE eb.bean_module = " . $db->quoted($parentType) . "
                  AND eb.bean_id = " . $db->quoted($parentId) . "
                  AND e.deleted = 0
                  ORDER BY e.date_sent DESC";
        
        $result = $db->query($query);
        $emails = array();
        
        while ($row = $db->fetchByAssoc($result)) {
            $emails[] = array(
                'id' => $row['id'],
                'type' => 'email',
                'icon' => 'envelope',
                'title' => $row['name'],
                'date' => $row['date_sent'],
                'status' => $row['status'],
                'description' => strip_tags($row['description_html']),
            );
        }
        
        return $emails;
    }
    
    /**
     * Get all meetings for a record
     */
    private static function getMeetings($parentType, $parentId) {
        global $db;
        
        $query = "SELECT m.id, m.name, m.date_start, m.duration_hours, m.duration_minutes, 
                         m.status, m.description
                  FROM meetings m
                  WHERE m.parent_type = " . $db->quoted($parentType) . "
                  AND m.parent_id = " . $db->quoted($parentId) . "
                  AND m.deleted = 0
                  ORDER BY m.date_start DESC";
        
        $result = $db->query($query);
        $meetings = array();
        
        while ($row = $db->fetchByAssoc($result)) {
            $meetings[] = array(
                'id' => $row['id'],
                'type' => 'meeting',
                'icon' => 'calendar',
                'title' => $row['name'],
                'date' => $row['date_start'],
                'status' => $row['status'],
                'duration' => ($row['duration_hours'] * 60) + $row['duration_minutes'],
                'description' => $row['description'],
            );
        }
        
        return $meetings;
    }
    
    /**
     * Get site visits (from custom tracking)
     */
    private static function getSiteVisits($parentType, $parentId) {
        global $db;
        
        $query = "SELECT lj.id, lj.name, lj.touchpoint_date, lj.touchpoint_data
                  FROM lead_journey lj
                  WHERE lj.parent_type = " . $db->quoted($parentType) . "
                  AND lj.parent_id = " . $db->quoted($parentId) . "
                  AND lj.touchpoint_type = 'site_visit'
                  AND lj.deleted = 0
                  ORDER BY lj.touchpoint_date DESC";
        
        $result = $db->query($query);
        $visits = array();
        
        while ($row = $db->fetchByAssoc($result)) {
            $data = json_decode($row['touchpoint_data'], true);
            $visits[] = array(
                'id' => $row['id'],
                'type' => 'site_visit',
                'icon' => 'globe',
                'title' => 'Site Visit: ' . ($data['page_title'] ?? 'Unknown Page'),
                'date' => $row['touchpoint_date'],
                'url' => $data['url'] ?? '',
                'duration' => $data['duration'] ?? 0,
                'description' => 'Visited: ' . ($data['url'] ?? ''),
            );
        }
        
        return $visits;
    }
    
    /**
     * Get LinkedIn clicks
     */
    private static function getLinkedInClicks($parentType, $parentId) {
        global $db;
        
        $query = "SELECT lj.id, lj.name, lj.touchpoint_date, lj.touchpoint_data
                  FROM lead_journey lj
                  WHERE lj.parent_type = " . $db->quoted($parentType) . "
                  AND lj.parent_id = " . $db->quoted($parentId) . "
                  AND lj.touchpoint_type = 'linkedin_click'
                  AND lj.deleted = 0
                  ORDER BY lj.touchpoint_date DESC";
        
        $result = $db->query($query);
        $clicks = array();
        
        while ($row = $db->fetchByAssoc($result)) {
            $data = json_decode($row['touchpoint_data'], true);
            $clicks[] = array(
                'id' => $row['id'],
                'type' => 'linkedin_click',
                'icon' => 'linkedin',
                'title' => 'LinkedIn: ' . ($data['action'] ?? 'Click'),
                'date' => $row['touchpoint_date'],
                'action' => $data['action'] ?? '',
                'url' => $data['url'] ?? '',
                'description' => $data['description'] ?? '',
            );
        }
        
        return $clicks;
    }
    
    /**
     * Get campaign responses
     */
    private static function getCampaignResponses($parentType, $parentId) {
        global $db;
        
        $query = "SELECT c.id, c.name, cl.date_modified, cl.activity_type
                  FROM campaigns c
                  JOIN campaign_log cl ON c.id = cl.campaign_id
                  WHERE cl.target_type = " . $db->quoted($parentType) . "
                  AND cl.target_id = " . $db->quoted($parentId) . "
                  AND c.deleted = 0
                  ORDER BY cl.date_modified DESC";
        
        $result = $db->query($query);
        $campaigns = array();
        
        while ($row = $db->fetchByAssoc($result)) {
            $campaigns[] = array(
                'id' => $row['id'],
                'type' => 'campaign',
                'icon' => 'bullhorn',
                'title' => 'Campaign: ' . $row['name'],
                'date' => $row['date_modified'],
                'activity' => $row['activity_type'],
                'description' => 'Activity: ' . $row['activity_type'],
            );
        }
        
        return $campaigns;
    }
    
    /**
     * Log a custom touchpoint
     */
    public static function logTouchpoint($parentType, $parentId, $touchpointType, $data = array()) {
        require_once('modules/LeadJourney/LeadJourneyThreading.php');

        $journey = BeanFactory::newBean('LeadJourney');
        $journey->parent_type = $parentType;
        $journey->parent_id = $parentId;
        $journey->touchpoint_type = $touchpointType;
        $journey->touchpoint_date = date('Y-m-d H:i:s');
        $journey->touchpoint_data = json_encode($data);
        $journey->name = $touchpointType . ' - ' . date('Y-m-d H:i:s');

        if (isset($data['source'])) {
            $journey->source = $data['source'];
        }

        // Set recording URL if provided
        if (isset($data['recording_url'])) {
            $journey->recording_url = $data['recording_url'];
        }

        // Generate thread ID for conversation grouping
        $journey->thread_id = LeadJourneyThreading::findOrCreateThreadId(
            $parentType,
            $parentId,
            $touchpointType,
            $data
        );

        $journey->save();
        return $journey->id;
    }

    /**
     * Get threaded conversation timeline for a lead/contact
     * Groups related activities into conversations
     */
    public static function getThreadedTimeline($parentType, $parentId) {
        require_once('modules/LeadJourney/LeadJourneyThreading.php');

        $flatTimeline = self::getJourneyTimeline($parentType, $parentId);
        return LeadJourneyThreading::groupIntoThreads($flatTimeline);
    }

    /**
     * Get custom touchpoints from lead_journey table (includes call/SMS logged by LeadJourneyLogger)
     */
    public static function getCustomTouchpoints($parentType, $parentId) {
        global $db;

        $query = "SELECT lj.id, lj.name, lj.touchpoint_date, lj.touchpoint_type,
                         lj.touchpoint_data, lj.thread_id, lj.recording_url
                  FROM lead_journey lj
                  WHERE lj.parent_type = " . $db->quoted($parentType) . "
                  AND lj.parent_id = " . $db->quoted($parentId) . "
                  AND lj.deleted = 0
                  ORDER BY lj.touchpoint_date DESC";

        $result = $db->query($query);
        $touchpoints = array();

        while ($row = $db->fetchByAssoc($result)) {
            $data = json_decode($row['touchpoint_data'], true) ?: [];
            $touchpoints[] = array(
                'id' => $row['id'],
                'type' => $row['touchpoint_type'],
                'icon' => self::getIconForType($row['touchpoint_type']),
                'title' => $row['name'],
                'date' => $row['touchpoint_date'],
                'thread_id' => $row['thread_id'],
                'recording_url' => $row['recording_url'] ?: ($data['recording_url'] ?? null),
                'touchpoint_data' => $data,
                'description' => $data['description'] ?? '',
            );
        }

        return $touchpoints;
    }

    /**
     * Get icon for touchpoint type
     */
    private static function getIconForType($type) {
        $icons = array(
            'call' => 'phone',
            'inbound_call' => 'phone',
            'outbound_call' => 'phone',
            'voicemail' => 'voicemail',
            'sms' => 'sms',
            'inbound_sms' => 'sms',
            'outbound_sms' => 'sms',
            'email' => 'envelope',
            'inbound_email' => 'envelope',
            'site_visit' => 'globe',
            'linkedin_click' => 'linkedin',
            'campaign' => 'bullhorn',
            'meeting' => 'calendar',
        );
        return $icons[$type] ?? 'circle';
    }
}
