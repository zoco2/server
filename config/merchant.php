<?php

return [
    // Staff official-account binding success notice. Mirrors kkvoice's clerk_profile_audit_result template.
    'staff_bind_notice' => [
        'title' => '报名审核结果通知',
        'template_short_id' => '48306',
        'keyword_name_list' => ['任务名称', '审核结果', '审核时间'],
        'field_map' => [
            'task_name' => 'thing1',
            'audit_result' => 'thing2',
            'audit_time' => 'time3',
        ],
    ],
];
