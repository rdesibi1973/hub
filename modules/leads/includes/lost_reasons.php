<?php
/**
 * Reasons a request can be marked Lost (slug => label), stored in requests.lost_reason.
 * Shared by request_view.php (status handler + modal) and requests.php (inline status).
 */
return [
    'insufficient_budget' => 'Insufficient budget',
    'trip_postponed'      => 'Trip postponed',
    'no_more_replies'     => 'No more replies',
    'agent_request'       => 'Agent request',
    'other'               => 'Other',
];
