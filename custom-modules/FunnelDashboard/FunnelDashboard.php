<?php
if(!defined('sugarEntry') || !sugarEntry) die('Not A Valid Entry Point');

class FunnelDashboard extends Basic {
    public $new_schema = true;
    public $module_dir = 'FunnelDashboard';
    public $object_name = 'FunnelDashboard';
    public $table_name = 'funnel_dashboard';
    public $importable = false;
    
    public $id;
    public $name;
    public $date_entered;
    public $date_modified;
    public $deleted;
    
    // Dashboard configuration fields
    public $category;
    public $funnel_config; // JSON field for funnel stages and rules

    public function __construct() {
        parent::__construct();
    }

    public function bean_implements($interface){
        // Disable ACL for this module - access controlled by SuiteCRM 8 navigation
        return false;
    }
    
    /**
     * Get funnel data by category
     */
    public static function getFunnelData($category = 'all', $dateFrom = null, $dateTo = null) {
        global $db;
        
        $data = array(
            'stages' => array(),
            'conversion_rates' => array(),
            'total_leads' => 0,
            'total_opportunities' => 0,
            'total_won' => 0,
        );
        
        // Build date filter
        $dateFilter = '';
        if ($dateFrom && $dateTo) {
            $dateFilter = " AND l.date_entered BETWEEN " . $db->quoted($dateFrom) . " AND " . $db->quoted($dateTo);
        }
        
        // Category filter
        $categoryFilter = '';
        if ($category !== 'all') {
            $categoryFilter = " AND l.lead_source = " . $db->quoted($category);
        }
        
        // Get leads by status
        $leadStages = self::getLeadsByStage($categoryFilter, $dateFilter);
        
        // Get opportunities by sales stage
        $oppStages = self::getOpportunitiesByStage($categoryFilter, $dateFilter);
        
        // Combine data
        $data['stages'] = array_merge($leadStages, $oppStages);
        $data['conversion_rates'] = self::calculateConversionRates($data['stages']);
        $data['total_leads'] = array_sum(array_column($leadStages, 'count'));
        $data['total_opportunities'] = array_sum(array_column($oppStages, 'count'));
        
        // Calculate won opportunities
        foreach ($oppStages as $stage) {
            if ($stage['stage'] === 'Closed Won') {
                $data['total_won'] = $stage['count'];
            }
        }
        
        return $data;
    }
    
    /**
     * Get leads grouped by status
     */
    private static function getLeadsByStage($categoryFilter, $dateFilter) {
        global $db;
        
        $query = "SELECT l.status, COUNT(*) as count, SUM(o.amount) as total_value
                  FROM leads l
                  LEFT JOIN opportunities o ON l.id = o.source_lead_id_c AND o.deleted = 0
                  WHERE l.deleted = 0 $categoryFilter $dateFilter
                  GROUP BY l.status
                  ORDER BY FIELD(l.status, 'New', 'Assigned', 'In Process', 'Converted', 'Recycled', 'Dead')";
        
        $result = $db->query($query);
        $stages = array();
        
        while ($row = $db->fetchByAssoc($result)) {
            $stages[] = array(
                'stage' => $row['status'],
                'type' => 'lead',
                'count' => (int)$row['count'],
                'value' => (float)($row['total_value'] ?? 0),
            );
        }
        
        return $stages;
    }
    
    /**
     * Get opportunities grouped by sales stage
     */
    private static function getOpportunitiesByStage($categoryFilter, $dateFilter) {
        global $db;
        
        $query = "SELECT o.sales_stage, COUNT(*) as count, SUM(o.amount) as total_value
                  FROM opportunities o
                  LEFT JOIN leads l ON o.source_lead_id_c = l.id AND l.deleted = 0
                  WHERE o.deleted = 0 $categoryFilter $dateFilter
                  GROUP BY o.sales_stage
                  ORDER BY FIELD(o.sales_stage, 'Prospecting', 'Qualification', 'Needs Analysis', 
                                'Value Proposition', 'Id. Decision Makers', 'Perception Analysis', 
                                'Proposal/Price Quote', 'Negotiation/Review', 'Closed Won', 'Closed Lost')";
        
        $result = $db->query($query);
        $stages = array();
        
        while ($row = $db->fetchByAssoc($result)) {
            $stages[] = array(
                'stage' => $row['sales_stage'],
                'type' => 'opportunity',
                'count' => (int)$row['count'],
                'value' => (float)($row['total_value'] ?? 0),
            );
        }
        
        return $stages;
    }
    
    /**
     * Calculate conversion rates between stages
     */
    private static function calculateConversionRates($stages) {
        $rates = array();
        
        for ($i = 0; $i < count($stages) - 1; $i++) {
            $current = $stages[$i];
            $next = $stages[$i + 1];
            
            if ($current['count'] > 0) {
                $rate = ($next['count'] / $current['count']) * 100;
                $rates[] = array(
                    'from' => $current['stage'],
                    'to' => $next['stage'],
                    'rate' => round($rate, 2),
                );
            }
        }
        
        return $rates;
    }
    
    /**
     * Get available categories (lead sources)
     */
    public static function getCategories() {
        global $db, $app_list_strings;
        
        $query = "SELECT DISTINCT lead_source 
                  FROM leads 
                  WHERE deleted = 0 AND lead_source IS NOT NULL AND lead_source != ''
                  ORDER BY lead_source";
        
        $result = $db->query($query);
        $categories = array('all' => 'All Categories');
        
        while ($row = $db->fetchByAssoc($result)) {
            $source = $row['lead_source'];
            $categories[$source] = $app_list_strings['lead_source_dom'][$source] ?? $source;
        }
        
        return $categories;
    }
    
    /**
     * Get funnel velocity metrics
     */
    public static function getFunnelVelocity($category = 'all', $days = 30) {
        global $db;
        
        $dateFrom = date('Y-m-d', strtotime("-$days days"));
        $dateTo = date('Y-m-d');
        
        $categoryFilter = '';
        if ($category !== 'all') {
            $categoryFilter = " AND l.lead_source = " . $db->quoted($category);
        }
        
        // Average time in each stage
        $query = "SELECT 
                    l.status,
                    AVG(DATEDIFF(l.date_modified, l.date_entered)) as avg_days
                  FROM leads l
                  WHERE l.deleted = 0 
                  AND l.date_entered >= " . $db->quoted($dateFrom) . "
                  $categoryFilter
                  GROUP BY l.status";
        
        $result = $db->query($query);
        $velocity = array();
        
        while ($row = $db->fetchByAssoc($result)) {
            $velocity[] = array(
                'stage' => $row['status'],
                'avg_days' => round((float)$row['avg_days'], 1),
            );
        }
        
        return $velocity;
    }
    
    /**
     * Get top performing categories
     */
    public static function getTopCategories($limit = 5) {
        global $db;
        
        $query = "SELECT 
                    l.lead_source,
                    COUNT(DISTINCT l.id) as lead_count,
                    COUNT(DISTINCT CASE WHEN l.status = 'Converted' THEN l.id END) as converted_count,
                    COUNT(DISTINCT o.id) as opp_count,
                    SUM(CASE WHEN o.sales_stage = 'Closed Won' THEN o.amount ELSE 0 END) as won_amount
                  FROM leads l
                  LEFT JOIN opportunities o ON l.id = o.source_lead_id_c AND o.deleted = 0
                  WHERE l.deleted = 0 
                  AND l.lead_source IS NOT NULL 
                  AND l.lead_source != ''
                  GROUP BY l.lead_source
                  ORDER BY won_amount DESC
                  LIMIT " . (int)$limit;
        
        $result = $db->query($query);
        $categories = array();
        
        while ($row = $db->fetchByAssoc($result)) {
            $conversionRate = $row['lead_count'] > 0 
                ? round(($row['converted_count'] / $row['lead_count']) * 100, 2) 
                : 0;
            
            $categories[] = array(
                'source' => $row['lead_source'],
                'lead_count' => (int)$row['lead_count'],
                'converted_count' => (int)$row['converted_count'],
                'opp_count' => (int)$row['opp_count'],
                'won_amount' => (float)$row['won_amount'],
                'conversion_rate' => $conversionRate,
            );
        }

        return $categories;
    }

    // ============================================================
    // MULTI-FUNNEL SUPPORT METHODS (Sales CRM Extension)
    // ============================================================

    /**
     * Get funnel types (Realtors, Senior Living, Home Care)
     */
    public static function getFunnelTypes() {
        return array(
            'all' => 'All Funnels',
            'Realtors' => 'Realtors',
            'Senior_Living' => 'Senior Living',
            'Home_Care' => 'Home Care',
        );
    }

    /**
     * Get funnel data by funnel type (using custom pipeline_stage_c)
     */
    public static function getFunnelDataByType($funnelType = 'all', $dateFrom = null, $dateTo = null) {
        global $db;

        $dateFrom = $dateFrom ?: date('Y-m-d', strtotime('-30 days'));
        $dateTo = $dateTo ?: date('Y-m-d');

        $data = array(
            'funnel_type' => $funnelType,
            'stages' => array(),
            'conversion_rates' => array(),
            'total_leads' => 0,
            'total_revenue' => 0,
            'total_won' => 0,
            'total_lost' => 0,
        );

        // Build filters
        $funnelFilter = '';
        if ($funnelType !== 'all') {
            $funnelFilter = " AND l.funnel_type_c = " . $db->quoted($funnelType);
        }

        $dateFilter = " AND l.date_entered BETWEEN " . $db->quoted($dateFrom . ' 00:00:00') . " AND " . $db->quoted($dateTo . ' 23:59:59');

        // Get leads by custom pipeline stage
        $query = "SELECT
                    COALESCE(l.pipeline_stage_c, 'New') as stage,
                    COUNT(*) as count,
                    SUM(COALESCE(l.expected_revenue_c, 0)) as pipeline_value
                  FROM leads l
                  WHERE l.deleted = 0 $funnelFilter $dateFilter
                  GROUP BY l.pipeline_stage_c
                  ORDER BY FIELD(COALESCE(l.pipeline_stage_c, 'New'),
                    'New', 'Contacting', 'Contacted', 'Qualified', 'Interested',
                    'Opportunity', 'Demo_Visit', 'Demo_Completed', 'Proposal', 'Negotiation',
                    'Closed_Won', 'Closed_Lost', 'Disqualified')";

        $result = $db->query($query);

        while ($row = $db->fetchByAssoc($result)) {
            $stageName = $row['stage'] ?: 'New';
            $data['stages'][] = array(
                'stage' => $stageName,
                'count' => (int)$row['count'],
                'value' => (float)$row['pipeline_value'],
            );

            $data['total_leads'] += (int)$row['count'];

            if ($stageName === 'Closed_Won') {
                $data['total_won'] = (int)$row['count'];
                $data['total_revenue'] = (float)$row['pipeline_value'];
            }
            if ($stageName === 'Closed_Lost') {
                $data['total_lost'] = (int)$row['count'];
            }
        }

        // Calculate conversion rates
        $data['conversion_rates'] = self::calculateConversionRates($data['stages']);

        // Overall win rate
        $closedTotal = $data['total_won'] + $data['total_lost'];
        $data['win_rate'] = $closedTotal > 0 ? round(($data['total_won'] / $closedTotal) * 100, 2) : 0;

        return $data;
    }

    /**
     * Get comparison data across all funnels
     */
    public static function getAllFunnelsComparison($dateFrom = null, $dateTo = null) {
        $funnelTypes = array('Realtors', 'Senior_Living', 'Home_Care');
        $comparison = array();

        foreach ($funnelTypes as $type) {
            $data = self::getFunnelDataByType($type, $dateFrom, $dateTo);
            $comparison[] = array(
                'funnel' => $type,
                'funnel_label' => str_replace('_', ' ', $type),
                'total_leads' => $data['total_leads'],
                'total_won' => $data['total_won'],
                'total_lost' => $data['total_lost'],
                'total_revenue' => $data['total_revenue'],
                'win_rate' => $data['win_rate'],
            );
        }

        return $comparison;
    }

    /**
     * Get revenue by funnel type
     */
    public static function getRevenueByFunnel($dateFrom = null, $dateTo = null) {
        global $db;

        $dateFrom = $dateFrom ?: date('Y-m-01');
        $dateTo = $dateTo ?: date('Y-m-d');

        $query = "SELECT
                    COALESCE(o.funnel_type_c, 'Unknown') as funnel_type,
                    COUNT(*) as deal_count,
                    SUM(o.amount) as total_revenue
                  FROM opportunities o
                  WHERE o.deleted = 0
                  AND o.sales_stage = 'Closed Won'
                  AND o.date_closed BETWEEN " . $db->quoted($dateFrom) . " AND " . $db->quoted($dateTo) . "
                  GROUP BY o.funnel_type_c
                  ORDER BY total_revenue DESC";

        $result = $db->query($query);
        $revenue = array();

        while ($row = $db->fetchByAssoc($result)) {
            $revenue[] = array(
                'funnel' => $row['funnel_type'],
                'funnel_label' => str_replace('_', ' ', $row['funnel_type']),
                'deal_count' => (int)$row['deal_count'],
                'total_revenue' => (float)$row['total_revenue'],
            );
        }

        return $revenue;
    }

    /**
     * Get revenue by BDM (user)
     */
    public static function getRevenueByBDM($funnelType = 'all', $dateFrom = null, $dateTo = null, $limit = 10) {
        global $db;

        $dateFrom = $dateFrom ?: date('Y-m-01');
        $dateTo = $dateTo ?: date('Y-m-d');

        $funnelFilter = '';
        if ($funnelType !== 'all') {
            $funnelFilter = " AND o.funnel_type_c = " . $db->quoted($funnelType);
        }

        $query = "SELECT
                    o.assigned_user_id,
                    u.first_name,
                    u.last_name,
                    COUNT(*) as deal_count,
                    SUM(o.amount) as total_revenue,
                    SUM(COALESCE(o.commission_amount_c, 0)) as total_commission
                  FROM opportunities o
                  JOIN users u ON o.assigned_user_id = u.id
                  WHERE o.deleted = 0
                  AND o.sales_stage = 'Closed Won'
                  AND o.date_closed BETWEEN " . $db->quoted($dateFrom) . " AND " . $db->quoted($dateTo) . "
                  $funnelFilter
                  GROUP BY o.assigned_user_id, u.first_name, u.last_name
                  ORDER BY total_revenue DESC
                  LIMIT " . (int)$limit;

        $result = $db->query($query);
        $bdmRevenue = array();

        while ($row = $db->fetchByAssoc($result)) {
            $bdmRevenue[] = array(
                'user_id' => $row['assigned_user_id'],
                'bdm_name' => $row['first_name'] . ' ' . $row['last_name'],
                'deal_count' => (int)$row['deal_count'],
                'total_revenue' => (float)$row['total_revenue'],
                'total_commission' => (float)$row['total_commission'],
            );
        }

        return $bdmRevenue;
    }

    /**
     * Get demos set metrics
     */
    public static function getDemosSetMetrics($funnelType = 'all', $userId = null, $dateFrom = null, $dateTo = null) {
        global $db;

        $dateFrom = $dateFrom ?: date('Y-m-01');
        $dateTo = $dateTo ?: date('Y-m-d');

        $filters = " WHERE l.deleted = 0 AND l.demo_scheduled_c = 1";
        $filters .= " AND l.demo_date_c BETWEEN " . $db->quoted($dateFrom . ' 00:00:00') . " AND " . $db->quoted($dateTo . ' 23:59:59');

        if ($funnelType !== 'all') {
            $filters .= " AND l.funnel_type_c = " . $db->quoted($funnelType);
        }

        if ($userId) {
            $filters .= " AND l.assigned_user_id = " . $db->quoted($userId);
        }

        // Demos scheduled
        $query = "SELECT
                    COUNT(*) as demos_scheduled,
                    SUM(CASE WHEN l.demo_completed_c = 1 THEN 1 ELSE 0 END) as demos_completed
                  FROM leads l
                  $filters";

        $result = $db->query($query);
        $row = $db->fetchByAssoc($result);

        $scheduled = (int)($row['demos_scheduled'] ?? 0);
        $completed = (int)($row['demos_completed'] ?? 0);

        return array(
            'demos_scheduled' => $scheduled,
            'demos_completed' => $completed,
            'completion_rate' => $scheduled > 0 ? round(($completed / $scheduled) * 100, 2) : 0,
        );
    }

    /**
     * Get follow-up rate metrics
     */
    public static function getFollowUpRateMetrics($funnelType = 'all', $userId = null) {
        global $db;

        $filters = " WHERE l.deleted = 0 AND l.follow_up_due_date_c IS NOT NULL AND l.follow_up_due_date_c <= CURDATE()";

        if ($funnelType !== 'all') {
            $filters .= " AND l.funnel_type_c = " . $db->quoted($funnelType);
        }

        if ($userId) {
            $filters .= " AND l.assigned_user_id = " . $db->quoted($userId);
        }

        // Follow-ups due vs completed (has activity after due date)
        $query = "SELECT
                    COUNT(*) as follow_ups_due,
                    SUM(CASE WHEN l.last_activity_date_c >= l.follow_up_due_date_c THEN 1 ELSE 0 END) as follow_ups_completed
                  FROM leads l
                  $filters";

        $result = $db->query($query);
        $row = $db->fetchByAssoc($result);

        $due = (int)($row['follow_ups_due'] ?? 0);
        $completed = (int)($row['follow_ups_completed'] ?? 0);

        return array(
            'follow_ups_due' => $due,
            'follow_ups_completed' => $completed,
            'completion_rate' => $due > 0 ? round(($completed / $due) * 100, 2) : 0,
            'overdue' => $due - $completed,
        );
    }

    /**
     * Get stalled leads (no activity for X days)
     */
    public static function getStalledLeads($funnelType = 'all', $daysThreshold = 7, $limit = 20) {
        global $db;

        $thresholdDate = date('Y-m-d H:i:s', strtotime("-$daysThreshold days"));

        $funnelFilter = '';
        if ($funnelType !== 'all') {
            $funnelFilter = " AND l.funnel_type_c = " . $db->quoted($funnelType);
        }

        $query = "SELECT
                    l.id,
                    l.first_name,
                    l.last_name,
                    l.funnel_type_c,
                    l.pipeline_stage_c,
                    l.assigned_user_id,
                    u.first_name as bdm_first_name,
                    u.last_name as bdm_last_name,
                    l.last_activity_date_c,
                    DATEDIFF(NOW(), COALESCE(l.last_activity_date_c, l.date_entered)) as days_stalled,
                    l.expected_revenue_c
                  FROM leads l
                  LEFT JOIN users u ON l.assigned_user_id = u.id
                  WHERE l.deleted = 0
                  AND l.pipeline_stage_c NOT IN ('Closed_Won', 'Closed_Lost', 'Disqualified')
                  AND (l.last_activity_date_c IS NULL OR l.last_activity_date_c < " . $db->quoted($thresholdDate) . ")
                  $funnelFilter
                  ORDER BY days_stalled DESC
                  LIMIT " . (int)$limit;

        $result = $db->query($query);
        $stalledLeads = array();

        while ($row = $db->fetchByAssoc($result)) {
            $stalledLeads[] = array(
                'id' => $row['id'],
                'name' => $row['first_name'] . ' ' . $row['last_name'],
                'funnel_type' => $row['funnel_type_c'],
                'pipeline_stage' => $row['pipeline_stage_c'],
                'bdm_name' => $row['bdm_first_name'] . ' ' . $row['bdm_last_name'],
                'days_stalled' => (int)$row['days_stalled'],
                'expected_revenue' => (float)($row['expected_revenue_c'] ?? 0),
                'last_activity' => $row['last_activity_date_c'],
            );
        }

        return $stalledLeads;
    }

    /**
     * Get pipeline velocity by stage
     */
    public static function getPipelineVelocity($funnelType = 'all', $days = 90) {
        global $db;

        $dateFrom = date('Y-m-d', strtotime("-$days days"));

        $funnelFilter = '';
        if ($funnelType !== 'all') {
            $funnelFilter = " AND l.funnel_type_c = " . $db->quoted($funnelType);
        }

        $query = "SELECT
                    COALESCE(l.pipeline_stage_c, 'New') as stage,
                    AVG(DATEDIFF(NOW(), COALESCE(l.stage_entry_date_c, l.date_entered))) as avg_days
                  FROM leads l
                  WHERE l.deleted = 0
                  AND l.date_entered >= " . $db->quoted($dateFrom) . "
                  AND l.pipeline_stage_c NOT IN ('Closed_Won', 'Closed_Lost', 'Disqualified')
                  $funnelFilter
                  GROUP BY l.pipeline_stage_c
                  ORDER BY FIELD(COALESCE(l.pipeline_stage_c, 'New'),
                    'New', 'Contacting', 'Contacted', 'Qualified', 'Interested',
                    'Opportunity', 'Demo_Visit', 'Demo_Completed', 'Proposal', 'Negotiation')";

        $result = $db->query($query);
        $velocity = array();

        while ($row = $db->fetchByAssoc($result)) {
            $velocity[] = array(
                'stage' => $row['stage'],
                'avg_days' => round((float)$row['avg_days'], 1),
            );
        }

        return $velocity;
    }

    // ============================================================
    // ROLE-BASED DASHBOARD METHODS
    // ============================================================

    /**
     * Determine user's dashboard role
     */
    public static function getUserDashboardRole($userId = null) {
        global $current_user, $db;

        $userId = $userId ?: $current_user->id;

        // Check for admin
        if ($current_user->is_admin) {
            return 'admin';
        }

        // Check user's title or role field for CRO/Sales Ops
        $query = "SELECT title FROM users WHERE id = " . $db->quoted($userId);
        $result = $db->query($query);
        $row = $db->fetchByAssoc($result);

        $title = strtolower($row['title'] ?? '');

        if (strpos($title, 'cro') !== false || strpos($title, 'chief revenue') !== false || strpos($title, 'vp sales') !== false) {
            return 'cro';
        }

        if (strpos($title, 'sales ops') !== false || strpos($title, 'operations manager') !== false || strpos($title, 'sales manager') !== false) {
            return 'sales_ops';
        }

        // Default to BDM
        return 'bdm';
    }

    /**
     * Get CRO Dashboard data (high-level overview)
     */
    public static function getCRODashboard($dateFrom = null, $dateTo = null) {
        $dateFrom = $dateFrom ?: date('Y-m-01');
        $dateTo = $dateTo ?: date('Y-m-d');

        return array(
            'period' => array('from' => $dateFrom, 'to' => $dateTo),
            'funnel_comparison' => self::getAllFunnelsComparison($dateFrom, $dateTo),
            'revenue_by_funnel' => self::getRevenueByFunnel($dateFrom, $dateTo),
            'revenue_by_bdm' => self::getRevenueByBDM('all', $dateFrom, $dateTo, 10),
            'pipeline_velocity' => self::getPipelineVelocity('all'),
            'demos_metrics' => self::getDemosSetMetrics('all', null, $dateFrom, $dateTo),
            'follow_up_metrics' => self::getFollowUpRateMetrics('all'),
            'stalled_leads' => self::getStalledLeads('all', 7, 10),
            'leaderboard' => class_exists('SalesTargets') ? SalesTargets::getLeaderboard('all', 'monthly') : array(),
        );
    }

    /**
     * Get Sales Ops Dashboard data (operational metrics)
     */
    public static function getSalesOpsDashboard($dateFrom = null, $dateTo = null) {
        $dateFrom = $dateFrom ?: date('Y-m-01');
        $dateTo = $dateTo ?: date('Y-m-d');

        return array(
            'period' => array('from' => $dateFrom, 'to' => $dateTo),
            'funnel_comparison' => self::getAllFunnelsComparison($dateFrom, $dateTo),
            'revenue_by_bdm' => self::getRevenueByBDM('all', $dateFrom, $dateTo, 20),
            'pipeline_velocity' => self::getPipelineVelocity('all'),
            'demos_metrics' => self::getDemosSetMetrics('all', null, $dateFrom, $dateTo),
            'follow_up_metrics' => self::getFollowUpRateMetrics('all'),
            'stalled_leads' => self::getStalledLeads('all', 7, 30),
            'underperformers' => class_exists('SalesTargets') ? SalesTargets::getUnderperformingBDMs(70) : array(),
            'package_sales' => class_exists('Packages') ? Packages::getPackageSalesSummary(null, null, $dateFrom, $dateTo) : array(),
        );
    }

    /**
     * Get BDM Dashboard data (personal metrics)
     */
    public static function getBDMDashboard($userId, $dateFrom = null, $dateTo = null) {
        global $db;

        $dateFrom = $dateFrom ?: date('Y-m-01');
        $dateTo = $dateTo ?: date('Y-m-d');

        // Get user's leads by stage
        $myLeads = self::getMyLeadsByStage($userId, $dateFrom, $dateTo);

        // Get personal targets
        $myTargets = class_exists('SalesTargets') ? SalesTargets::getTargetsForUser($userId) : array();

        // Calculate achievements
        $achievements = array();
        foreach ($myTargets as $target) {
            $achievements[] = SalesTargets::calculateAchievement($target['id']);
        }

        // Get personal revenue
        $query = "SELECT
                    COUNT(*) as deal_count,
                    SUM(amount) as total_revenue,
                    SUM(COALESCE(commission_amount_c, 0)) as total_commission
                  FROM opportunities
                  WHERE assigned_user_id = " . $db->quoted($userId) . "
                  AND sales_stage = 'Closed Won'
                  AND date_closed BETWEEN " . $db->quoted($dateFrom) . " AND " . $db->quoted($dateTo) . "
                  AND deleted = 0";
        $result = $db->query($query);
        $revenueRow = $db->fetchByAssoc($result);

        // Get leaderboard position
        $leaderboard = class_exists('SalesTargets') ? SalesTargets::getLeaderboard('all', 'monthly') : array();
        $myPosition = 0;
        foreach ($leaderboard as $index => $entry) {
            if ($entry['target_user_id'] === $userId) {
                $myPosition = $index + 1;
                break;
            }
        }

        return array(
            'period' => array('from' => $dateFrom, 'to' => $dateTo),
            'my_leads' => $myLeads,
            'my_targets' => $myTargets,
            'achievements' => $achievements,
            'revenue' => array(
                'deal_count' => (int)($revenueRow['deal_count'] ?? 0),
                'total_revenue' => (float)($revenueRow['total_revenue'] ?? 0),
                'total_commission' => (float)($revenueRow['total_commission'] ?? 0),
            ),
            'demos_metrics' => self::getDemosSetMetrics('all', $userId, $dateFrom, $dateTo),
            'follow_up_metrics' => self::getFollowUpRateMetrics('all', $userId),
            'my_stalled_leads' => self::getMysStalledLeads($userId, 7),
            'leaderboard_position' => $myPosition,
            'leaderboard_total' => count($leaderboard),
        );
    }

    /**
     * Get leads by stage for a specific user
     */
    private static function getMyLeadsByStage($userId, $dateFrom = null, $dateTo = null) {
        global $db;

        $dateFilter = '';
        if ($dateFrom && $dateTo) {
            $dateFilter = " AND l.date_entered BETWEEN " . $db->quoted($dateFrom . ' 00:00:00') . " AND " . $db->quoted($dateTo . ' 23:59:59');
        }

        $query = "SELECT
                    COALESCE(l.pipeline_stage_c, 'New') as stage,
                    COUNT(*) as count,
                    SUM(COALESCE(l.expected_revenue_c, 0)) as pipeline_value
                  FROM leads l
                  WHERE l.deleted = 0
                  AND l.assigned_user_id = " . $db->quoted($userId) . "
                  $dateFilter
                  GROUP BY l.pipeline_stage_c
                  ORDER BY FIELD(COALESCE(l.pipeline_stage_c, 'New'),
                    'New', 'Contacting', 'Contacted', 'Qualified', 'Interested',
                    'Opportunity', 'Demo_Visit', 'Demo_Completed', 'Proposal', 'Negotiation',
                    'Closed_Won', 'Closed_Lost', 'Disqualified')";

        $result = $db->query($query);
        $stages = array();

        while ($row = $db->fetchByAssoc($result)) {
            $stages[] = array(
                'stage' => $row['stage'],
                'count' => (int)$row['count'],
                'value' => (float)$row['pipeline_value'],
            );
        }

        return $stages;
    }

    /**
     * Get stalled leads for a specific user
     */
    private static function getMysStalledLeads($userId, $daysThreshold = 7) {
        global $db;

        $thresholdDate = date('Y-m-d H:i:s', strtotime("-$daysThreshold days"));

        $query = "SELECT
                    l.id,
                    l.first_name,
                    l.last_name,
                    l.funnel_type_c,
                    l.pipeline_stage_c,
                    l.last_activity_date_c,
                    DATEDIFF(NOW(), COALESCE(l.last_activity_date_c, l.date_entered)) as days_stalled,
                    l.expected_revenue_c
                  FROM leads l
                  WHERE l.deleted = 0
                  AND l.assigned_user_id = " . $db->quoted($userId) . "
                  AND l.pipeline_stage_c NOT IN ('Closed_Won', 'Closed_Lost', 'Disqualified')
                  AND (l.last_activity_date_c IS NULL OR l.last_activity_date_c < " . $db->quoted($thresholdDate) . ")
                  ORDER BY days_stalled DESC
                  LIMIT 10";

        $result = $db->query($query);
        $stalledLeads = array();

        while ($row = $db->fetchByAssoc($result)) {
            $stalledLeads[] = array(
                'id' => $row['id'],
                'name' => $row['first_name'] . ' ' . $row['last_name'],
                'funnel_type' => $row['funnel_type_c'],
                'pipeline_stage' => $row['pipeline_stage_c'],
                'days_stalled' => (int)$row['days_stalled'],
                'expected_revenue' => (float)($row['expected_revenue_c'] ?? 0),
            );
        }

        return $stalledLeads;
    }
}
