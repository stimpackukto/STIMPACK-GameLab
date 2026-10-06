<?php
declare(strict_types=1);

function catalog_file(string $type): string
{
    $base = dirname(__DIR__) . '/data/catalog';

    return match ($type) {
        'game' => $base . '/games.json',
        'app'  => $base . '/apps.json',
        default => throw new RuntimeException('잘못된 catalog type'),
    };
}

function catalog_load(string $type): array
{
    $file = catalog_file($type);

    if (!is_file($file)) {
        return [];
    }

    $raw = file_get_contents($file);

    if ($raw === false || trim($raw) === '') {
        return [];
    }

    $data = json_decode($raw, true);

    return is_array($data) ? $data : [];
}

function catalog_add(string $type, array $item): void
{
    $file = catalog_file($type);
    $dir = dirname($file);

    if (!is_dir($dir)) {
        mkdir($dir, 0770, true);
    }

    $fp = fopen($file, 'c+');

    if (!$fp) {
        throw new RuntimeException('저장 파일을 열 수 없습니다.');
    }

    try {
        if (!flock($fp, LOCK_EX)) {
            throw new RuntimeException('파일 잠금 실패');
        }

        rewind($fp);
        $raw = stream_get_contents($fp);

        $items = [];

        if (is_string($raw) && trim($raw) !== '') {
            $decoded = json_decode($raw, true);

            if (is_array($decoded)) {
                $items = $decoded;
            }
        }

        $items[] = $item;

        $json = json_encode(
            $items,
            JSON_PRETTY_PRINT |
            JSON_UNESCAPED_UNICODE |
            JSON_UNESCAPED_SLASHES
        );

        if ($json === false) {
            throw new RuntimeException('JSON 생성 실패');
        }

        ftruncate($fp, 0);
        rewind($fp);
        fwrite($fp, $json);
        fflush($fp);

        flock($fp, LOCK_UN);

    } finally {
        fclose($fp);
    }
}

function catalog_image_name_from_url(string $type, string $url): string
{
    if ($type === 'app') {

        $query = (string)parse_url($url, PHP_URL_QUERY);

        parse_str($query, $params);

        $packageId = trim((string)($params['id'] ?? ''));

        if (
            $packageId === '' ||
            !preg_match('/^[a-zA-Z0-9._-]+$/', $packageId)
        ) {
            throw new RuntimeException(
                'Play Store URL에서 앱 ID를 확인할 수 없습니다.'
            );
        }

        return $packageId;
    }


    if ($type === 'game') {

        $path = (string)parse_url($url, PHP_URL_PATH);

        $parts = array_values(
            array_filter(
                explode('/', trim($path, '/')),
                static fn($v) => $v !== ''
            )
        );

        /*
         * /games/crimescene/
         *
         * [0] games
         * [1] crimescene
         */

        if (
            count($parts) < 2 ||
            strtolower($parts[0]) !== 'games'
        ) {
            throw new RuntimeException(
                '게임 URL은 /games/폴더명/ 형태로 입력해주세요.'
            );
        }

        $folder = trim($parts[1]);

        if (
            $folder === '' ||
            !preg_match('/^[a-zA-Z0-9_-]+$/', $folder)
        ) {
            throw new RuntimeException(
                '게임 폴더명을 확인할 수 없습니다.'
            );
        }

        return strtolower($folder);
    }


    throw new RuntimeException('잘못된 이미지 종류입니다.');
}


function catalog_upload_image(
    string $field,
    string $type,
    string $url
): string {

    if (
        empty($_FILES[$field]) ||
        ($_FILES[$field]['error'] ?? UPLOAD_ERR_NO_FILE)
            === UPLOAD_ERR_NO_FILE
    ) {
        return '';
    }


    if (!function_exists('imagewebp')) {
        throw new RuntimeException(
            '서버에서 WebP 변환 기능을 사용할 수 없습니다.'
        );
    }


    $file = $_FILES[$field];


    if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
        throw new RuntimeException('이미지 업로드에 실패했습니다.');
    }


    if (($file['size'] ?? 0) > 10 * 1024 * 1024) {
        throw new RuntimeException(
            '이미지는 10MB 이하만 가능합니다.'
        );
    }


    $tmp = (string)$file['tmp_name'];


    /*
     * 실제 이미지인지 확인
     */

    $info = @getimagesize($tmp);

    if ($info === false) {
        throw new RuntimeException(
            '정상적인 이미지 파일이 아닙니다.'
        );
    }


    $allowed = [
        IMAGETYPE_JPEG,
        IMAGETYPE_PNG,
        IMAGETYPE_WEBP,
    ];


    if (!in_array($info[2], $allowed, true)) {
        throw new RuntimeException(
            'JPG, PNG, WEBP 이미지만 가능합니다.'
        );
    }


    /*
     * URL을 기준으로 파일명 결정
     */

    $baseName = catalog_image_name_from_url(
        $type,
        $url
    );


    if ($type === 'app') {

        $dir = dirname(__DIR__)
            . '/assets/images/apps';

        $webPath =
            '/assets/images/apps/'
            . $baseName
            . '.webp';

    } else {

        $dir = dirname(__DIR__)
            . '/assets/images/games';

        $webPath =
            '/assets/images/games/'
            . $baseName
            . '.webp';
    }


    if (!is_dir($dir)) {

        if (!mkdir($dir, 0775, true)) {
            throw new RuntimeException(
                '이미지 저장 폴더를 만들 수 없습니다.'
            );
        }
    }


    /*
     * 원본 이미지 읽기
     */

    $raw = file_get_contents($tmp);

    if ($raw === false) {
        throw new RuntimeException(
            '이미지를 읽을 수 없습니다.'
        );
    }


    $source = @imagecreatefromstring($raw);

    if ($source === false) {
        throw new RuntimeException(
            '이미지 변환에 실패했습니다.'
        );
    }


    $srcW = imagesx($source);
    $srcH = imagesy($source);


    /*
     * 최종 규격
     *
     * 1280 × 720
     * 16:9
     */

    $dstW = 1280;
    $dstH = 720;

    $targetRatio = $dstW / $dstH;
    $sourceRatio = $srcW / $srcH;


    /*
     * 중앙 기준 cover crop
     */

    if ($sourceRatio > $targetRatio) {

        // 원본이 가로로 너무 넓음

        $cropH = $srcH;
        $cropW = (int)round(
            $srcH * $targetRatio
        );

        $srcX = (int)(($srcW - $cropW) / 2);
        $srcY = 0;

    } else {

        // 원본이 세로로 너무 높음

        $cropW = $srcW;
        $cropH = (int)round(
            $srcW / $targetRatio
        );

        $srcX = 0;
        $srcY = (int)(($srcH - $cropH) / 2);
    }


    $dest = imagecreatetruecolor(
        $dstW,
        $dstH
    );


    if ($dest === false) {

        imagedestroy($source);

        throw new RuntimeException(
            '이미지 생성에 실패했습니다.'
        );
    }


    /*
     * 1280 × 720 변환
     */

    imagecopyresampled(
        $dest,
        $source,
        0,
        0,
        $srcX,
        $srcY,
        $dstW,
        $dstH,
        $cropW,
        $cropH
    );


    $savePath =
        $dir
        . '/'
        . $baseName
        . '.webp';


    /*
     * WebP 품질 88
     */

    if (!imagewebp(
        $dest,
        $savePath,
        88
    )) {

        imagedestroy($source);
        imagedestroy($dest);

        throw new RuntimeException(
            'WebP 이미지 저장에 실패했습니다.'
        );
    }


    imagedestroy($source);
    imagedestroy($dest);


    return $webPath;
}

function catalog_valid_url(string $url): bool
{
    if ($url === '') {
        return true;
    }

    if (str_starts_with($url, '/') && !str_starts_with($url, '//')) {
        return true;
    }

    if (!filter_var($url, FILTER_VALIDATE_URL)) {
        return false;
    }

    $scheme = strtolower((string)parse_url($url, PHP_URL_SCHEME));

    return in_array($scheme, ['http', 'https'], true);
}

/* =========================================================
 * 기존 항목 수정
 * ========================================================= */

function catalog_update(
    string $type,
    string $id,
    array $newItem
): void {

    $file = catalog_file($type);

    if (!is_file($file)) {
        throw new RuntimeException('등록 데이터를 찾을 수 없습니다.');
    }

    $fp = fopen($file, 'c+');

    if (!$fp) {
        throw new RuntimeException('저장 파일을 열 수 없습니다.');
    }

    try {

        if (!flock($fp, LOCK_EX)) {
            throw new RuntimeException('파일 잠금 실패');
        }

        rewind($fp);

        $raw = stream_get_contents($fp);

        $items = [];

        if (is_string($raw) && trim($raw) !== '') {

            $decoded = json_decode($raw, true);

            if (is_array($decoded)) {
                $items = $decoded;
            }
        }


        $found = false;

        foreach ($items as $index => $item) {

            if (
                (string)($item['id'] ?? '')
                === $id
            ) {

                $items[$index] = $newItem;

                $found = true;

                break;
            }
        }


        if (!$found) {
            throw new RuntimeException(
                '수정할 항목을 찾을 수 없습니다.'
            );
        }


        $json = json_encode(
            $items,
            JSON_PRETTY_PRINT |
            JSON_UNESCAPED_UNICODE |
            JSON_UNESCAPED_SLASHES
        );


        if ($json === false) {
            throw new RuntimeException('JSON 생성 실패');
        }


        ftruncate($fp, 0);

        rewind($fp);

        fwrite($fp, $json);

        fflush($fp);

        flock($fp, LOCK_UN);

    } finally {

        fclose($fp);
    }
}


/* =========================================================
 * 기존 ID 기준 대표이미지 교체
 *
 * 수정할 때 URL이 바뀌더라도
 * ID 및 리뷰 연결은 유지
 * ========================================================= */

function catalog_upload_image_by_id(
    string $field,
    string $type,
    string $id
): string {

    if (
        empty($_FILES[$field]) ||
        ($_FILES[$field]['error'] ?? UPLOAD_ERR_NO_FILE)
            === UPLOAD_ERR_NO_FILE
    ) {
        return '';
    }


    if (!function_exists('imagewebp')) {

        throw new RuntimeException(
            '서버에서 WebP 변환 기능을 사용할 수 없습니다.'
        );
    }


    if (
        !preg_match(
            '/^[a-zA-Z0-9._-]+$/',
            $id
        )
    ) {

        throw new RuntimeException(
            '잘못된 콘텐츠 ID입니다.'
        );
    }


    $file = $_FILES[$field];


    if (
        ($file['error'] ?? UPLOAD_ERR_OK)
        !== UPLOAD_ERR_OK
    ) {

        throw new RuntimeException(
            '이미지 업로드에 실패했습니다.'
        );
    }


    if (
        ($file['size'] ?? 0)
        > 10 * 1024 * 1024
    ) {

        throw new RuntimeException(
            '이미지는 10MB 이하만 가능합니다.'
        );
    }


    $tmp = (string)$file['tmp_name'];


    $info = @getimagesize($tmp);

    if ($info === false) {

        throw new RuntimeException(
            '정상적인 이미지 파일이 아닙니다.'
        );
    }


    if (
        !in_array(
            $info[2],
            [
                IMAGETYPE_JPEG,
                IMAGETYPE_PNG,
                IMAGETYPE_WEBP
            ],
            true
        )
    ) {

        throw new RuntimeException(
            'JPG, PNG, WEBP 이미지만 가능합니다.'
        );
    }


    $raw = file_get_contents($tmp);

    if ($raw === false) {

        throw new RuntimeException(
            '이미지를 읽을 수 없습니다.'
        );
    }


    $source = @imagecreatefromstring($raw);

    if ($source === false) {

        throw new RuntimeException(
            '이미지 변환에 실패했습니다.'
        );
    }


    $srcW = imagesx($source);
    $srcH = imagesy($source);

    $dstW = 1280;
    $dstH = 720;

    $targetRatio =
        $dstW / $dstH;

    $sourceRatio =
        $srcW / $srcH;


    if ($sourceRatio > $targetRatio) {

        $cropH = $srcH;

        $cropW = (int)round(
            $srcH * $targetRatio
        );

        $srcX =
            (int)(($srcW - $cropW) / 2);

        $srcY = 0;

    } else {

        $cropW = $srcW;

        $cropH = (int)round(
            $srcW / $targetRatio
        );

        $srcX = 0;

        $srcY =
            (int)(($srcH - $cropH) / 2);
    }


    $dest = imagecreatetruecolor(
        $dstW,
        $dstH
    );


    imagecopyresampled(
        $dest,
        $source,
        0,
        0,
        $srcX,
        $srcY,
        $dstW,
        $dstH,
        $cropW,
        $cropH
    );


    if ($type === 'app') {

        $dir =
            dirname(__DIR__)
            . '/assets/images/apps';

        $webPath =
            '/assets/images/apps/'
            . $id
            . '.webp';

    } elseif ($type === 'game') {

        $dir =
            dirname(__DIR__)
            . '/assets/images/games';

        $webPath =
            '/assets/images/games/'
            . $id
            . '.webp';

    } else {

        imagedestroy($source);
        imagedestroy($dest);

        throw new RuntimeException(
            '잘못된 종류입니다.'
        );
    }


    if (!is_dir($dir)) {

        mkdir(
            $dir,
            0775,
            true
        );
    }


    $savePath =
        $dir
        . '/'
        . $id
        . '.webp';


    if (
        !imagewebp(
            $dest,
            $savePath,
            88
        )
    ) {

        imagedestroy($source);
        imagedestroy($dest);

        throw new RuntimeException(
            'WebP 저장에 실패했습니다.'
        );
    }


    imagedestroy($source);
    imagedestroy($dest);


    return $webPath;
}