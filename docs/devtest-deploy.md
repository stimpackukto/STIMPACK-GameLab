## 이메일 '오너 확인' 간소화 (2026-11-27)

- **메일 버튼 한 번으로 확인 완료**: 메일에서 `오너 확인` 클릭 → `/test/confirm.php`에서 JavaScript가 **백그라운드 POST** → `/api/devtest/confirm.php`에서 `owner_confirmed_at` 저장
- **Google 재로그인과 2차 확인 버튼은 없음**. 메일 수신자는 본인 메일에 포함된 64자리 랜덤 토큰을 증명으로 사용한다.
- 메일 URL의 `application_id`만으로는 처리되지 않는다. 서버에는 SHA-256 **토큰 해시만** 저장하고, 원본 토큰은 이메일 링크에만 포함한다.
- `GET` 요청은 DB를 수정하지 않고, 실제 변경은 토큰을 포함한 API `POST`에서만 수행한다. 메일 링크 미리보기의 단순 GET은 확인 처리되지 않는다. 다만 JavaScript까지 실행하는 보안 스캐너는 자동 확인을 유발할 수 있으므로, 완전한 사람 클릭 검증이 필요하다면 별도 버튼 확인 방식이 필요하다.
- 동일 링크를 다시 눌러도 이미 기록된 확인 시간은 바뀌지 않으며, 신청자의 `신청 완료`는 `오너 확인`으로 표시된다. 모집 인원은 변경되지 않는다.
- **기존에 발송된 메일에는 토큰이 없다.** 따라서 과거의 `/test/confirm.php?application_id=4` 등의 링크로는 자동 확인할 수 없다. 신규 신청 메일부터 적용한다.
- 서버의 공개 접근 주소는 `/test/confirm.php`, 실제 쓰기 API는 `/api/devtest/confirm.php`다. PHP-FPM 사용자는 기존 DB 접속 권한만 필요하다.

### 운영 DB 마이그레이션 (한 번만)

기존 `owner_confirmed_at` 컬럼이 있다는 전제하에 아래 파일만 실행한다:

```bash
cd /var/www/gamelab
mysql gamelab < sql/20261127_devtest_owner_confirm_token.sql
```

이 파일은 기존 신청 기록을 유지하고 `owner_confirm_token_hash` 컬럼 하나만 추가한다. **SQL 실행 후 코드 배포**.
변경 파일: `includes/devtest.php`, `test/index.php`, `test/confirm.php`, `api/devtest/confirm.php`.
기존 WoW SMTP 설정, `includes/devtest_mail.php`, Google 인증/앱 소유권 검증 로직은 그대로 유지.
테스트: 새 신청 메일의 버튼 클릭 → 화면의 확인 안내 → DB `owner_confirmed_at` 채워지는지 확인 → 신청자가 목록 새로고침 → `오너 확인` 표기. 반드시 재확인 날짜가 바뀌지 않는지 테스트.

## 이메일 오너 확인 기능 서버 적용 (2026-11-27)

**기존 단계**: `owner_confirmed_at` 컬럼이 없는 서버만 이전 SQL을 먼저 실행한다. 기존 데이터는 보존되며 이전 요청은 `owner_confirmed_at=NULL`로 유지된다.

```bash
cd /var/www/gamelab
mysql gamelab < sql/20261127_devtest_owner_confirm.sql
```

- 위 `ALTER TABLE`은 **한 번만** 실행. 두 번째 실행 시 중복 컬럼 오류 발생
- 이후 최신 `test/index.php`, `test/confirm.php`, `api/devtest/confirm.php`, `includes/devtest.php` 배포
- 기존 Google OAuth 설정(허용된 `/test/` 반환 경로)은 수정할 필요 없음. 메일 확인에서는 OAuth를 호출하지 않는다
- 비로그인 상태의 이메일 버튼 클릭 → 백그라운드 POST → 확인 날짜 저장
- 다른 Google 계정으로 로그인되어 있어도 이메일 토큰이 유효하면 확인 가능. 토큰 없는 임의 POST는 거절
- 이메일 앱의 단순 GET 미리보기는 상태를 바꾸지 않는다. JS까지 실행하는 스캐너는 예외일 수 있음
- 신청자에서 페이지 새로고침 후 `오너 확인` 표시 확인. `recruited_count`는 변경되지 않아야 함
- 메일 전송은 HTML 버튼과 텍스트 링크 대체 본문을 모두 제공; 기존 WoW Gmail SMTP 계정 그대로 사용

# STIMPACK GAME LAB 자체 개발테스트 센터

URL: /test/ (기존 헤더의 '개발테스트' 메뉴)

## 기능
- 기존 GameLab 구글 인증 세션에서 검증된 Google sub 사용
- '모집 중'에서 앱명, 설명/테스트 목적, 모집 완료 인원, 목표 모집 인원 등록
- 참여 신청 알림 수신 이메일은 **앱 등록 당시 구글 인증 이메일(owner_email)** 로 고정. 등록 또는 수정 POST에서 다른 수신 이메일을 지정해도 무시됨
- 대표 이미지 1장(JPG/PNG/WEBP, 최대 5MB), 소개 영상 링크(유튜브·쇼츠·틱톡, 선택), **Google Play 링크(선택)**. Google Play 링크는 일반 앱 상세 주소 또는 공식 테스터 참여 주소를 허용
- 카드에는 대표 이미지와 영상 링크, Google Play 링크가 등록된 경우 'Google Play' 버튼을 표시. 모집 중인 타인 앱에는 '테스트 참여' 버튼을 표시
- '테스트 참여' 클릭 → 대화상자에서 참여할 이메일 입력 → 신청 기록 → **WoW와 동일한 PHPMailer + Gmail SMTP**로 등록 당시 소유자 구글 인증 이메일에 전송
- 로그인한 Google sub마다 앱별 신청 1회(중복 불가). 앱 등록자는 자기 앱에 신청 불가
- **이메일 하단에 '오너 확인' 버튼** 추가. `https://stimpack.pe.kr/test/confirm.php?application_id=N&token=비밀값` 으로 이동
- 메일 URL GET은 DB를 변경하지 않고, 유효한 랜덤 토큰을 가진 확인 페이지에서 자동 POST를 수행한다. Google 로그인은 필요 없다.
- API는 이메일로 발송한 토큰의 SHA-256 해시를 확인하고 `owner_confirmed_at`만 기록한다. 중복 확인은 멱등 처리
- 테스터 본인에게는 `신청 완료` → `오너 확인` 버튼으로 표시 변경(새로고침 시 반영). 이는 신청서 수신 확인일 뿐 테스터 승인·설치 인증이 아님
- 이미 발송된 기존 이메일의 확인 링크에는 보안 토큰이 없으며, 과거 신청 기록은 그대로 유지됨. 자동 재발송은 하지 않음
- 신청 이메일은 입력한 문자열을 앱 소유자에게 알려주며 별도 이메일 소유 확인은 하지 않음. 앱 설치·Play 테스트 등록이나 모집 완료 인원 자동 집계는 수행하지 않음
- SMTP 발송 성공은 SMTP 서버에 제출 성공을 의미하며 실제 수신함 도착까지 보장하지 않음
- 이미지 교체·삭제 가능. 수정 시 새 이미지를 올리지 않으면 기존 이미지 유지
- 소유자는 모집·테스트 중 프로젝트 정보를 수정할 수 있음
- 소유자만 '테스트 진행': 모집 중 → 테스트 중
- 소유자만 '완료': 테스트 중 → 완료
- 완료된 프로젝트는 보관되고 수정/재개 불가
- 한 Google sub는 진행 상태(recruiting/testing) 앱을 동시에 1개만 보유. 완료하면 새 앱 등록 가능
- 목록은 Google 인증 사용자에게만 공개
- Play Store의 참여/설치/리뷰/평가/보상과는 전혀 연동하지 않음
- '현재 모집 완료 인원'은 현재 단계에서 앱 소유자가 직접 기입하는 수치이며 실제 테스터 자동 집계가 아님

## 서버 배포 순서

1. 서버에 이미 존재하는 /var/www/gamelab/test/index.php는 Git 추적에 없던 기존 페이지임.
   덮어쓰거나 git pull 하기 전에 해당 파일을 별도 경로에 백업.
2. GitHub Desktop에서 변경사항 Pull. 새 test/index.php 포함.
3. 운영 서버에 SQL 마이그레이션을 **1회** 실행. (DB 이름 gamelab)
   예: mysql gamelab < /var/www/gamelab/sql/20261127_devtest_projects.sql
   실제 DB 계정/권한 및 경로에 맞춰 조정할 것. 관리자/DBA가 적용.
   최초 설치는 sql/20261127_devtest_projects.sql 적용 후, **이번 확장 마이그레이션**
   sql/20261127_devtest_media.sql 을 **추가로 1회** 실행.
   그리고 참여 신청 기록용 `sql/20261127_devtest_applications.sql` 을 실행(반복해도 CREATE TABLE IF NOT EXISTS).
   **Google Play 링크 컬럼**은 `sql/20261127_devtest_google_play.sql` 을 1회 실행해 추가한다.
   이미 기본 테이블이 설치된 서버에서는 적용하지 않은 기존 확장 SQL을 확인하고, **이번 변경에는 owner_confirm_token SQL만 실행**한다(단, owner_confirmed_at 컬럼은 이미 있어야 함).
   `ALTER TABLE`은 이메일·이미지 경로·영상 주소 컬럼만 추가하며 기존 행은 보존(기존 행 신규 컬럼 NULL).
   예: `mysql gamelab < /var/www/gamelab/sql/20261127_devtest_media.sql`
   (media/google_play ALTER TABLE 파일은 반복 실행하면 컬럼 중복 오류 발생).
4. 대표 이미지 업로드 디렉터리 `/var/www/gamelab/uploads/devtest/`를 준비하고
   **PHP-FPM 실행 계정이 쓸 수 있도록 권한**을 설정.
   예시(실제 PHP-FPM 계정이 www-data일 때):
   `sudo install -d -m 0755 -o www-data -g www-data /var/www/gamelab/uploads/devtest`
   PHP의 `upload_max_filesize`는 최소 5M, `post_max_size`는 최소 6M 권장.
   `/uploads/`는 .gitignore 대상이므로 새로 배포해도 이미지 파일은 유지되어야 함.
   서버는 업로드 경로의 PHP/스크립트 실행을 차단하고, 이미지는 정적 파일로만 제공해야 함.
5. 새 test/index.php, includes/devtest.php, **includes/devtest_mail.php**, 변경된 auth/google_login.php,
   auth/google_callback.php 등을 기존 PHP 배포 방식대로 배포.
   /etc/stimpack/google_oauth.php, includes/config.php 및 기존 PHP-FPM 설정은 변경 금지.
6. WoW 메일 발송과 같은 `/etc/trinity-web.env` 설정(`GMAIL_SMTP_HOST`, `GMAIL_SMTP_USER`, `GMAIL_SMTP_PASS`, `GMAIL_SMTP_FROM`, `GMAIL_SMTP_FROM_NAME`, `GMAIL_SMTP_PORT`, `GMAIL_SMTP_SECURE`)을 재사용. **비밀번호는 GitHub에 올리지 말 것.**
   현재 PHPMailer 설치를 자동 탐색하는 경로: `/var/www/gamelab/includes/PHPMailer/src`, `/var/www/gamelab/wow/includes/PHPMailer/src`, `/var/www/stimpack/includes/PHPMailer/src`.
   WoW의 설치 위치가 다르면 `/etc/trinity-web.env`에 선택 항목 `GAMELAB_PHPMAILER_SRC=/절대경로/PHPMailer/src`를 추가할 수 있음.
   PHP-FPM 실행 계정이 설정 파일을 읽을 권한이 있어야 함(기존 WoW 메일이 성공한다면 설정을 그대로 재사용).
7. 브라우저에서 /test/ 방문 후 구글 로그인(로그인 후 /test/ 복귀),
   구글 인증 수신 이메일 자동 지정/대표 이미지 1장 첨부/유튜브·틱톡 링크 등록/Google Play 주소 등록,
   카드 이미지·영상·Google Play 버튼 확인/테스트 참여 대화상자/신청 이메일 발송 및 기록/이미지 교체·삭제/모집 인원 수정/상태 이동/재등록을 테스트.

## 추천 점검 시나리오
1. 비로그인: 로그인 유도, 앱 등록/상태 변경 불가
2. 첫 번째 Google 계정: 앱 A 등록 → 두 번째 앱 등록 불가
3. 두 번째 Google 계정: 앱 A의 '테스트 진행'/'완료' 버튼 없음. POST 위조로도 상태 변경 불가
4. A 소유자: 모집 중 → 테스트 중 → 완료, 역방향 변경 불가
5. 완료 처리 후 A 소유자: 앱 B 등록 가능. 완료된 앱 A 기록 유지
6. CSRF 누락·불일치, 임의 status·id·마이너스 모집 인원 등 거부
7. 동시에 2개의 등록 POST를 보내도 고유키로 한 개만 허용
8. 이미지 확장자 위조, PHP/SVG, 5MB 초과, 다중 파일 업로드 거절
9. 영상 URL은 유튜브·틱톡 HTTPS만 허용하며 `javascript:` 및 외부 도메인 거절
10. 이미지를 재첨부하지 않고 수정하면 기존 이미지가 유지되고 교체/삭제 시 구 이미지 정리
11. 등록 당시의 Google 이메일과 다른 수신 주소를 POST로 보내도 원래 등록자(owner_email)에게만 이메일 전송
12. 다른 Google sub가 기존 앱 수정/테스트 진행/완료 POST를 보내도 거절
13. 테스트 신청 시 다른 사람 이메일 입력: 알림에 미인증 이메일임을 표시. 신청 계정당 앱별 중복 신청 거절
14. PHP mail() 발송 실패 시에는 '메일 발송 요청 실패' 안내 및 해당 신청 재시도 가능
15. 신규 애플리케이션 SQL 누락 시 페이지에 준비 중 안내 표시
16. Google Play 링크 미입력 앱: 기존 카드 및 등록 정상 작동, 버튼 숨김
17. 등록한 앱 수정: Google Play 링크 추가/변경/비움 후 카드 반영, 기존 앱·테스터 신청 기록·리뷰 영향 없음
18. 앱 상세 페이지(https://play.google.com/store/apps/details?id=...)와 공식 테스터 참여 링크(https://play.google.com/apps/testing/...) 허용. 외부 사이트·HTTP·자격증명 URL 거부

## 주의
- GitHub 반영만으로는 운영 DB가 변경되지 않는다. **PHP 코드를 배포하기 전에 미적용된 SQL을 실행**한다. 특히 이번 수정의 `sql/20261127_devtest_google_play.sql`이 없으면 페이지에 준비 중 안내가 표시된다.
- 사용자의 실제 앱 설치, 기기 사용 여부, 테스터 인증/피드백 검증 기능은 이번 단계에 구현하지 않았다.
- 서버에 미추적 test/index.php가 존재하면 Git pull 과정에서 untracked file 충돌이 발생할 수 있다. 백업 후 충돌 파일을 옮기고 Pull 한다.


## 이메일 발송이 안 될 때 (운영 서버에서 확인)

개발테스트는 PHP `mail()`을 사용하지 않고, `includes/devtest_mail.php`에서 WoW와 동일한 PHPMailer + Gmail SMTP 설정을 사용한다.
전송에 성공하면 SMTP 서버가 메시지를 접수했다는 뜻이며 실제 수신함에 도착했는지는 별도 확인이 필요하다.

### 1. WoW 메일 설정·PHPMailer 설치 확인
```bash
# 비밀번호가 포함되므로 설정 파일 내용은 출력하거나 공유하지 말 것
sudo test -r /etc/trinity-web.env && echo '환경 설정 파일 존재'
sudo find /var/www/gamelab /var/www/stimpack -path '*/PHPMailer/src/PHPMailer.php' -print 2>/dev/null
```
환경 파일은 PHP-FPM 실행 계정에서 읽을 수 있어야 한다. 비밀번호를 로그/채팅에 출력하지 않는다.

### 2. 요청 후 로그 확인
```bash
sudo journalctl -u php8.1-fpm --since '15 minutes ago' --no-pager | grep -E 'GAME LAB DEVTEST|DEVTEST SMTP'
```
로그 위치는 PHP-FPM 설정에 따라 다를 수 있다.
`config_unreadable`: PHP-FPM 계정이 `/etc/trinity-web.env`를 읽을 수 없음.
`config_invalid`: SMTP 설정 값 누락 또는 형식 오류.
`phpmailer_not_found`: 기존 WoW PHPMailer 라이브러리 경로가 다름.
`send_failed`: SMTP 로그인/접속/발송 중 오류.
`SMTP accepted ... delivery unconfirmed`: SMTP 서버에 발송 요청은 접수됨.

### 3. 메일 경로 설정
- 웹앱에 SMTP 비밀번호를 하드코딩하거나 Git 저장소에 올리지 않는다.
- 운영 서버의 WoW에서 이미 사용하는 Gmail SMTP 계정 정보를 재사용한다. 별도의 Postfix 설치는 필요 없다.
- `GMAIL_SMTP_FROM`에 지정된 발신 주소의 실제 발신 권한/SPF/DKIM/DMARC를 점검한다.
- 참여 신청 DB의 `mail_sent_at` 값은 SMTP 발송 요청 성공 시각이며 수신 완료 증거가 아니다.
