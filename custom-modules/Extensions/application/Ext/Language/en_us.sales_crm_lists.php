<?php
if(!defined('sugarEntry') || !sugarEntry) die('Not A Valid Entry Point');

// Funnel Types - The three sales verticals
$app_list_strings['funnel_type_list'] = array(
    '' => '',
    'Realtors' => 'Realtors',
    'Senior_Living' => 'Senior Living',
    'Home_Care' => 'Home Care',
);

// Pipeline Stages - Custom sales pipeline
$app_list_strings['pipeline_stage_list'] = array(
    '' => '',
    'New' => 'New',
    'Contacting' => 'Contacting',
    'Contacted' => 'Contacted',
    'Qualified' => 'Qualified',
    'Interested' => 'Interested',
    'Opportunity' => 'Opportunity',
    'Demo_Visit' => 'Demo/Visit Scheduled',
    'Demo_Completed' => 'Demo/Visit Completed',
    'Proposal' => 'Proposal Sent',
    'Negotiation' => 'Negotiation',
    'Closed_Won' => 'Closed Won',
    'Closed_Lost' => 'Closed Lost',
    'Disqualified' => 'Disqualified',
);

// Sales Target Types
$app_list_strings['sales_target_type_list'] = array(
    'bdm' => 'BDM (Individual)',
    'team' => 'Team',
);

// Period Types
$app_list_strings['sales_period_type_list'] = array(
    'monthly' => 'Monthly',
    'quarterly' => 'Quarterly',
    'annual' => 'Annual',
);

// Billing Frequency
$app_list_strings['billing_frequency_list'] = array(
    '' => '',
    'one-time' => 'One-Time',
    'monthly' => 'Monthly',
    'quarterly' => 'Quarterly',
    'annual' => 'Annual',
);

// User Role Types (for dashboard access)
$app_list_strings['sales_role_type_list'] = array(
    '' => '',
    'cro' => 'CRO (Chief Revenue Officer)',
    'sales_ops' => 'Sales Operations Manager',
    'bdm' => 'Business Development Manager',
    'admin' => 'Administrator',
);

// Alert Types
$app_list_strings['sales_alert_type_list'] = array(
    'stalled_lead' => 'Stalled Lead',
    'missed_followup' => 'Missed Follow-up',
    'underperformance' => 'Underperformance',
    'target_at_risk' => 'Target at Risk',
    'high_value_idle' => 'High-Value Opportunity Idle',
);

// Alert Severity
$app_list_strings['sales_alert_severity_list'] = array(
    'low' => 'Low',
    'medium' => 'Medium',
    'high' => 'High',
    'critical' => 'Critical',
);

// Alert Status
$app_list_strings['sales_alert_status_list'] = array(
    'active' => 'Active',
    'acknowledged' => 'Acknowledged',
    'resolved' => 'Resolved',
    'dismissed' => 'Dismissed',
);

// ACL Action Labels for Role Management UI
$app_strings['LBL_ACTION_CRO_DASHBOARD'] = 'CRO Dashboard';
$app_strings['LBL_ACTION_SALESOPS_DASHBOARD'] = 'Sales Ops Dashboard';
$app_strings['LBL_ACTION_BDM_DASHBOARD'] = 'BDM Dashboard';
$app_strings['LBL_ACTION_DASHBOARD'] = 'Dashboard';
