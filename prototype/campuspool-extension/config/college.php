<?php

return [
    'domains' => ['stu.pes.edu'],
    'code' => env('MOBILITY_COLLEGE_CODE', 'CAMPUS-BLR'),
    'otp_ttl' => 300,
    'otp_resend_after' => 30,
    'otp_max_attempts' => 5,
];
