<?php
$hook_version = 1;
$hook_array = Array();

$hook_array['after_save'] = Array();
$hook_array['after_save'][] = Array(
    10,
    'Create folder for inbound email',
    'custom/modules/InboundEmail/InboundEmailFolderHook.php',
    'InboundEmailFolderHook',
    'afterSave'
);
