# HousingStudy 학습자 게시판 · 무료 서버 배포 안내

Android HousingStudy v0.5.0의 다섯 번째 게시판 메뉴에서 https://stimpack.pe.kr/housingstudy/ 를 Custom Tabs로 엽니다.

## 구조

- 기존 STIMPACK GAME LAB의 Google OAuth 로그인 및 PHP 세션, PDO MariaDB 재사용.
- 게시글 읽기는 로그인 없이 허용. 글쓰기·댓글·신고·차단·삭제는 구글 로그인 후 허용.
- 게시판 DB에는 Google 이메일·실명이 저장되지 않고, Google subject의 SHA-256 해시와 가명만 보관.
- 자료: https 링크 및 PDF·JPG·PNG 파일 최대 5 MB. 첨부는 웹 루트 외부 /var/lib/gamelab/housingstudy_uploads 에 저장.
- 신고 관리 화면: /housingstudy/moderation.php (기존 GameLab 관리자 Google 계정).
- 기존 자체 서버 외 유료 서비스 없음. DB·디스크 점유 비용은 별도.

## 서버 적용 순서

1. cd /var/www/gamelab && git pull origin main
2. 기존 GameLab에 연결된 DB 이름을 확인:

    php -r "require '/var/www/gamelab/includes/db.php'; echo gamelab_db()->query('SELECT DATABASE()')->fetchColumn(), PHP_EOL;"

3. 출력된 DB 이름을 DATABASE_NAME 자리에 넣고 스키마 설치:

    mysql -u root -p DATABASE_NAME < /var/www/gamelab/housingstudy/schema.sql

   MySQL root가 소켓 인증이면 sudo mysql DATABASE_NAME < /var/www/gamelab/housingstudy/schema.sql 방법 사용.

4. PHP-FPM 사용자 www-data 기준 비공개 첨부 디렉터리 생성:

    sudo install -d -o www-data -g www-data -m 0750 /var/lib/gamelab/housingstudy_uploads

   PHP 실행 계정이 다르면 적절한 계정으로 바꾸세요. 웹 사용자에게 전역 777 권한을 주면 안 됩니다.

5. https://stimpack.pe.kr/housingstudy/ 접속 → 게시글 읽기 → 구글 로그인 후 복귀 → 글/댓글/PDF 첨부 및 신고 테스트.

## 운영 도메인 주의

- **정식 주소는 https://stimpack.pe.kr/housingstudy/** 입니다. `forum.pe.kr`은 초기 개발용 주소였고 HousingStudy 앱에서는 더 이상 사용하지 않습니다.
- `stimpack.pe.kr`의 Nginx 문서 루트 또는 `/housingstudy/` 경로가 실제 `/var/www/gamelab/housingstudy/`를 가리키는지 확인해야 합니다. 확인 전에는 기존 웹사이트 라우팅이나 도메인 설정을 함부로 덮어쓰지 마세요.
- 기존 Google OAuth 클라이언트 `/etc/stimpack/google_oauth.php`의 `redirect_uri`가 어느 도메인인지 확인하세요. OAuth 콜백은 `stimpack.pe.kr` 호스트여야 로그인 세션 쿠키가 같은 도메인에서 유지됩니다. Google Cloud의 승인된 리디렉션 URI에도 정확히 등록되어야 합니다.
- 웹 페이지와 API의 `/housingstudy/` 상대 경로는 유지하므로 호스트 변경 외에는 수정할 필요가 없습니다.

## 유의사항

- GitHub 소스 업데이트만으로 서버 SQL이 설치되거나 업로드 폴더가 생성되지 않습니다.
- 기존 /etc/stimpack/google_oauth.php 클라이언트 설정은 그대로 사용합니다. OAuth 리다이렉트 이후 허용되는 내부 복귀 경로만 /housingstudy/ 추가했습니다.
- 개인정보 및 게시물 정책은 /housingstudy/policy.php에서 확인할 수 있습니다.
- 구글 로그인은 WebView를 사용하지 않고 신뢰 가능한 브라우저(Custom Tabs)에서 진행합니다.
- 테스트 전 실제 서버의 HTTPS, PHP-FPM, MariaDB 연결 및 파일 접근권한을 확인하세요. 서버 배포 및 실제 앱 실행 검증은 아직 진행되지 않았습니다.
