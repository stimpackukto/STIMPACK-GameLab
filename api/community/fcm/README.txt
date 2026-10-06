설치 위치:
/var/www/nextcloud/api/community/fcm/

업로드 파일:
fcm_common.php
login.php
register_token.php
categories.php
save_preferences.php

DB 생성:
mysql -u root -p < install.sql

앱 기본 호출 경로:
https://stimpack.pe.kr/api/community/fcm/login.php
https://stimpack.pe.kr/api/community/fcm/register_token.php
https://stimpack.pe.kr/api/community/fcm/categories.php
https://stimpack.pe.kr/api/community/fcm/save_preferences.php

필수 PHP 확장:
mysqli
gmp

로그인 API 입력:
account
password
device_id

로그인 API 출력:
success
message
account_id
auth_token
display_name
