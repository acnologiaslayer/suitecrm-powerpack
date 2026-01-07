<?php
$hook_version = 1;
$hook_array = Array();

$hook_array['after_save'] = Array();
$hook_array['after_save'][] = Array(10, 'Save email case updates', 'modules/AOP_Case_Updates/CaseUpdatesHook.php', 'CaseUpdatesHook', 'saveEmailUpdate');
$hook_array['after_save'][] = Array(20, 'Link email to leads', 'custom/modules/Emails/EmailToLeadLinker.php', 'EmailToLeadLinker', 'afterSave');
$hook_array['after_save'][] = Array(30, 'Link email to folder', 'custom/modules/InboundEmail/InboundEmailFolderHook.php', 'InboundEmailFolderHook', 'linkEmailToFolder');
