# STIMPACK 장기 — 계정 연결 및 AI 기보 수집 서버 설치

## 핵심 설계

- 기존 `/auth/google_login.php`의 Google OAuth/PHP 세션 재사용.
- 신규 회원정보 DB 없음. 계정 이름·이메일·프로필 이미지·동의 선택은 **이용자 기기 localStorage**에 보관.
- 서버는 **명시적으로 동의된 완료 기보**와 연계용 **HMAC-SHA256 가명 계정키**만 저장.
- 같은 원본 Google `sub`는 서버 비밀키 없이 복원할 수 없도록 가명화.
- 서버는 개인정보 조회 API `/api/janggi/account.php`, 기보 POST/DELETE API `/api/janggi/records.php`만 제공.
- 서버 내 수집 기보는 AI 연구 및 학습 후보 데이터이며, 서버 규칙 재검증/노이즈 제거/실제 AI 훈련 파이프라인은 별도 후속 작업입니다.

## 1. DB 테이블 적용 (운영자)

기존 GAME LAB MySQL에 다음 SQL 파일을 적용합니다.

```bash
mysql -u <DB_USER> -p <DB_NAME> < sql/20261022_janggi_training_records.sql
```

기본 SQL 파일은 서버 데이터베이스 계정명이나 비밀번호를 포함하지 않습니다. DB 연결 정보는 기존 `includes/db.php`의 GAME LAB 설정을 이용합니다.

## 2. 서버 비밀키 (계정 연결에도 필수)

계정 확인 API는 로그인한 사용자의 Google `sub`를 **서버 전용 HMAC-SHA256 비밀키**로 가명화합니다. 운영 서버에 이 키가 없으면 계정 관리에서 **503**이 발생합니다.

PHP-FPM 환경변수 `STIMPACK_JANGGI_ACCOUNT_HMAC_SECRET` 또는 서버 전용 비밀 파일 `/etc/stimpack/janggi_account_hmac.key` 중 한 곳에서 읽습니다. 두 API 모두 `includes/janggi_account_key.php`를 사용합니다. PHP-FPM 환경변수가 있는 경우 그 값이 우선합니다. 비밀키는 GitHub나 웹 루트에 절대 두지 마세요.

### 가장 간단한 서버 전용 파일 방식

서버에서 아래 명령을 실행합니다(기존 키가 있으면 덮어쓰지 않음).

```bash
sudo test -d /etc/stimpack || echo '/etc/stimpack 디렉터리를 먼저 확인하세요'
sudo sh -c 'umask 077; test -s /etc/stimpack/janggi_account_hmac.key || openssl rand -hex 32 > /etc/stimpack/janggi_account_hmac.key'
sudo chown root:www-data /etc/stimpack/janggi_account_hmac.key
sudo chmod 640 /etc/stimpack/janggi_account_hmac.key
sudo ls -l /etc/stimpack/janggi_account_hmac.key
```

출력에는 키 **내용을 표시하지 마세요.** `www-data`가 PHP-FPM 실행 그룹이 아닌 경우 해당 그룹으로 변경해야 합니다. 상위 `/etc/stimpack` 디렉터리도 PHP-FPM 프로세스가 탐색할 수 있어야 합니다. 로그인 계정에 연결된 기존 기보가 있다면 **키를 절대 교체하지 마세요**.

다음 PHP 소스를 운영 서버에도 반영해야 합니다.

- `includes/janggi_account_key.php` 신규
- `api/janggi/account.php` 업데이트
- `api/janggi/records.php` 업데이트

PHP 코드만 변경한 경우 Nginx 재시작은 불필요합니다. PHP OPcache에서 파일 타임스탬프 검사가 꺼져 있다면 PHP-FPM 재시작이 필요할 수 있습니다.

### 환경변수 방식(대안)

`STIMPACK_JANGGI_ACCOUNT_HMAC_SECRET`을 PHP-FPM 풀 환경변수로 제공해도 됩니다. 이 방식은 FPM 풀 설정 변경 및 재시작이 필요할 수 있습니다. 운영 방식은 하나로 통일하고, 두 경로의 키가 다르지 않도록 주의하세요.

**중요:** 계정 연결 자체에는 SQL 테이블 생성이 필요하지 않습니다. 기보 서버 저장을 활성화할 때 1단계의 DB 마이그레이션과 별도 수집/보존 테스트가 필요합니다.

## 3. 동일 출처 웹 배포 (필수)

장기 Phaser 앱을 `https://stimpack.pe.kr/` 도메인 아래 경로로 서비스해야 합니다. 장기는 배포 경로를 강제하지 않으며 예를 들면 `/janggi/`에 배치할 수 있습니다.

- `https://stimpack.pe.kr/auth/google_login.php`: 기존 Google 로그인
- `https://stimpack.pe.kr/api/janggi/account.php`: 로그인 사용자 확인
- `https://stimpack.pe.kr/api/janggi/records.php`: 동의된 기보 수신/본인 기보 삭제
- `https://stimpack.pe.kr/janggi_privacy.html`: 수집·이용 정책 안내

로그인은 새 브라우저 탭에서 진행되며 성공 후 GAME LAB 메인으로 돌아갑니다. 이용자는 장기 탭으로 돌아와 **연결 확인**을 눌러야 합니다. 이 과정에서 Google 토큰을 장기 앱에 전달하지 않습니다.

로컬 `http://localhost:8000`, 다른 도메인, Android WebView의 별도 출처에서는 의도적으로 전송을 차단합니다. Android 네이티브까지 지원하려면 별도의 안전한 로그인/출처 통합 설계가 필요합니다. 와일드카드 CORS, 임의 토큰 전달, 사용자 임의 아이디로 전송하는 방식은 사용하지 않습니다.

## 4. 개인정보 정책 및 선택 동의

계정 연결과 AI 기보 수집 동의는 **별개 선택**입니다. 계정만 연결해도 대국할 수 있고, 동의 체크는 초기에는 해제되어 있습니다.

동의 대상: 종료된 장기 수순 좌표·승패·사용 엔진·대국시간·초기 배치·종료 사유·게임 ID. 계정 이름/이메일/Google 로그인 토큰/기기 설정은 기보 DB에 포함되지 않습니다. 연락처, IP, 앱 사용 분석 값도 기보 요청 payload에 포함되지 않습니다(인프라 웹서버 접속 로그는 별도 관리 필요).

- 철회: 이용자가 기기에서 동의를 꺼서 **이후** 기보 전송을 중단.
- 삭제: 로그인 확인 후 `DELETE /api/janggi/records.php`에서 해당 계정 가명키에 연결된 모든 기보 제거.
- 보유: **최대 365일**, 다음 CLI 정리 작업을 운영 cron으로 실행해야 실제 삭제됩니다.

```cron
15 3 * * * /usr/bin/php /var/www/gamelab/scripts/janggi_prune_records.php
```

`/var/www/gamelab`은 서버 실제 배포 경로에 맞게 조정합니다. Cron 등록 전에는 자동 보유기간 삭제가 동작하지 않습니다. DB 백업본의 보존·삭제 정책도 별도로 확인해야 합니다.

## 5. 점검

1. 비로그인 `GET /api/janggi/account.php` → `authenticated:false`
2. 로그인 후 확인 → 가명 `user.id` 및 표시용 프로필 반환 (장기 계정 DB 저장 없음)
3. 동의 안 함 → 기보 POST 없음
4. 동의 후 실제 대국 종료 → 기보 1건 POST (게임 ID로 중복 방지)
5. 동의 철회 후 대국 종료 → POST 없음
6. 계정 연결 해제 → 기기 프로필/동의 사라짐, Google 세션은 유지
7. 삭제 → 인증된 계정의 서버 기보 삭제 후 로컬 동의도 철회
8. 365일 이전 기보 → 정리 cron으로 삭제

주의: POST는 데이터 구조와 소유 계정은 검증하지만 현재 서버에서 **장기 규칙 전체를 재연산하여 수순 유효성을 검증하지는 않습니다.** 실제 AI 훈련에 투입하기 전 규칙 검사/중복 제거/악의적 기보 필터를 반드시 추가하세요.
