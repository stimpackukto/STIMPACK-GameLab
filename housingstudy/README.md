# HousingStudy 학습자 게시판 · 무료 서버 배포 안내

Android HousingStudy v0.6.0의 다섯 번째 게시판 메뉴는 Android 네이티브 BoardActivity를 엽니다. 웹 주소 https://stimpack.pe.kr/housingstudy/ 는 브라우저 이용자용으로 유지합니다.

## v0.6.0 Android 네이티브 구글 로그인 및 API

Android 앱에서는 게시판을 웹페이지로 열지 않습니다. `BoardActivity`가 게시글 목록/상세/글쓰기/댓글/신고/차단을 직접 표시하며, Android Credential Manager가 구글 계정 선택 UI를 제공합니다.

- 엔드포인트: `https://stimpack.pe.kr/housingstudy/api.php`
- 공개 조회: `GET ?action=list`, `GET ?action=post&id=1`
- 구글 인증: `GET ?action=nonce` (기존 서버 웹 OAuth 클라이언트 ID + 일회성 nonce), `POST action=login` (Google ID token + nonce)
- 서버 검증: Google tokeninfo TLS 검증 + 발급자/audience/만료/nonce 확인, nonce 1회 소비, 14일 앱 전용 세션 발급. Google ID 토큰 자체는 DB에 저장하지 않습니다.
- 로그인 API: `POST action=session`, `POST action=logout`
- 작성 API: `POST action=create/comment/delete/delete_comment/report/block`. 모든 작성 요청에는 앱 로그인 세션 토큰이 필요하며 공개 게시글 조회는 로그인 없이 가능합니다.
- 기존의 웹 로그인/웹 게시판은 계속 지원합니다. 두 방식은 동일한 `housingstudy_posts`, `housingstudy_comments` DB 데이터를 공유합니다. 앱 로그인 세션과 웹 PHP 쿠키 로그인은 별개입니다.
- 앱 로그인 비밀키는 Android Keystore에 암호화해 저장하고, 서버 DB에는 토큰의 SHA-256 해시만 저장합니다.

### 앱 로그인 배포 필수 절차

1. 기존 웹사이트 `/var/www/gamelab`에서 `git pull origin main`.
2. **이전 v0.5 버전에서 4개 테이블을 생성했더라도 SQL 재적용 필수!** DB 추가 생성:

    sudo mysql gamelab < /var/www/gamelab/housingstudy/schema.sql

   기존 4개와 앱 로그인용 2개를 포함해 총 6개 테이블을 확인:

    sudo mysql gamelab -e "SHOW TABLES LIKE 'housingstudy_%';"

3. 기존 PHP-FPM이 `/etc/stimpack/google_oauth.php`를 읽을 수 있어야 합니다. 웹 OAuth의 웹 클라이언트 ID를 구글 인증 토큰 검증에 사용하며 **비밀번호/비밀키를 Android나 GitHub에 복사하지 않습니다.**
4. Google Cloud Console의 **같은 프로젝트**에서 패키지 `pe.kr.forum.housingstudy`와 해당 APK 서명의 **SHA-1**로 Android 유형 OAuth 클라이언트를 등록합니다. 개발용 debug와 Google Play 앱 서명 SHA-1은 달라서 각각 설정해야 합니다.
5. `curl -i https://stimpack.pe.kr/housingstudy/api.php?action=nonce` 응답에서 200 및 `ok:true`를 확인합니다. 웹 클라이언트 ID는 공개 식별값이지만 반환된 nonce는 잠시 후 만료됩니다.
6. Android v0.6.0 설치 후 앱 내부 **게시판 → Google 계정 선택 → 글쓰기/댓글**을 실제 기기에서 점검합니다. PHP 문법 검사만으로 OAuth/네트워크 성공은 보장되지 않습니다.
7. 기존 웹 저장소 외 추가 서버 가입/구독은 없습니다. 업로드 5MB 제한은 유지됩니다.

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
