<?php
declare(strict_types=1);
// Run daily from server-side cron only. Do not expose this script on the web.
if (PHP_SAPI!=='cli') {http_response_code(403);exit;}
require_once dirname(__DIR__).'/includes/db.php';
try {
    $stmt=gamelab_db()->prepare('DELETE FROM gamelab_janggi_training_records WHERE created_at < NOW() - INTERVAL 365 DAY');
    $stmt->execute();
    fwrite(STDOUT, 'Expired Janggi records deleted: '.$stmt->rowCount().PHP_EOL);
}catch(Throwable $e){
    fwrite(STDERR, 'Janggi record retention job failed'.PHP_EOL);
    exit(1);
}
