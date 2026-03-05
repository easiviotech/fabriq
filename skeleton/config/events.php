<?php

declare(strict_types=1);

/**
 * Event bus (Redis Streams) configuration.
 */
return [
    'stream_prefix' => 'sf:events:',
    'consumer_group' => 'sf_consumers',
];
