# STIMPACK GAME LAB 자체 개발테스트 센터

URL: /test/ (기존 헤더의 '개발테스트' 메뉴)

## 기능
- 기존 GameLab 구글 인증 세션에서 검증된 Google sub 사용
- '모집 중'에서 앱명, 설명/테스트 목적, 모집 완료 인원, 목표 모집 인원 등록
- 참여 신청 알림 수신 이메일은 **앱 등록 당시 구글 인증 이메일(owner_email)** 로 고정. 등록 또는 수정 POST에서 다른 수신 이메일을 지정해도 무시됨
- 대표 이미지 1장(JPG/PNG/WEBP, 최대 5MB), 소개 영상 링크(유튜브·쇼츠·틱톡, 선택)
- 카드에는 대표 이미지와 영상 링크, 모집 중인 타인 앱에는 '테스트 참여' 버튼을 표시
- '테스트 참여' 클릭 → 대화상자에서 참여할 이메일 입력 → 서버에 신청 기록 → 등록 당시 소유자 구글 인증 이메일로 PHP mail() 알림
- 로그인한 Google sub마다 앱별 신청 1회(중복 불가). 앱 등록자는 자기 앱에 신청 불가
- 신청 이메일은 입력한 문자열을 앱 소유자에게 알려주며 별도 이메일 소유 확인은 하지 않음. 앱 설치·Play 테스트 등록이나 모집 완료 인원 자동 집계는 수행하지 않음
- PHP mail() 발송 요청 성공은 실제 메일함 수신 보장이 아니므로 서버 메일 시스템 구성·검증 필요
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
   이미 기본 테이블이 설치된 서버는 media 확장 SQL(아직 적용되지 않았다면)과 applications 신규 SQL만 실행.
   `ALTER TABLE`은 이메일·이미지 경로·영상 주소 컬럼만 추가하며 기존 행은 보존(기존 행 신규 컬럼 NULL).
   예: `mysql gamelab < /var/www/gamelab/sql/20261127_devtest_media.sql`
   (두 번째 파일은 반복 실행하면 컬럼 중복 오류 발생).
4. 대표 이미지 업로드 디렉터리 `/var/www/gamelab/uploads/devtest/`를 준비하고
   **PHP-FPM 실행 계정이 쓸 수 있도록 권한**을 설정.
   예시(실제 PHP-FPM 계정이 www-data일 때):
   `sudo install -d -m 0755 -o www-data -g www-data /var/www/gamelab/uploads/devtest`
   PHP의 `upload_max_filesize`는 최소 5M, `post_max_size`는 최소 6M 권장.
   `/uploads/`는 .gitignore 대상이므로 새로 배포해도 이미지 파일은 유지되어야 함.
   서버는 업로드 경로의 PHP/스크립트 실행을 차단하고, 이미지는 정적 파일로만 제공해야 함.
5. 새 test/index.php, includes/devtest.php, 변경된 auth/google_login.php,
   auth/google_callback.php 등을 기존 PHP 배포 방식대로 배포.
   /etc/stimpack/google_oauth.php, includes/config.php 및 기존 PHP-FPM 설정은 변경 금지.
6. 서버의 PHP `mail()` 함수가 실제 발송을 접수할 수 있도록 메일 전송 설정(MTA/SMTP relay, SPF·DKIM 등)을 점검. 기존 `tester/join.php`도 동일한 `noreply@stimpack.pe.kr` From 헤더를 사용함. 메일 서버 로그와 실제 수신 메일함 양쪽 확인 필요.
7. 브라우저에서 /test/ 방문 후 구글 로그인(로그인 후 /test/ 복귀),
   구글 인증 수신 이메일 자동 지정/대표 이미지 1장 첨부/유튜브·틱톡 링크 등록,
   카드 이미지·영상 링크 확인/테스트 참여 대화상자/신청 이메일 발송 및 기록/이미지 교체·삭제/모집 인원 수정/상태 이동/재등록을 테스트.

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

## 주의
- GitHub 반영만으로는 운영 DB가 변경되지 않는다. **PHP 코드를 배포하기 전에 media 및 applications SQL을 먼저 적용**한다. 컬럼 또는 테이블이 없으면 페이지에 준비 중 안내가 표시된다.
- 사용자의 실제 앱 설치, 기기 사용 여부, 테스터 인증/피드백 검증 기능은 이번 단계에 구현하지 않았다.
- 서버에 미추적 test/index.php가 존재하면 Git pull 과정에서 untracked file 충돌이 발생할 수 있다. 백업 후 충돌 파일을 옮기고 Pull 한다.


## 이메일 발송이 안 될 때 (운영 서버에서 확인)

`includes/devtest.php`는 PHP `mail()` 함수를 사용한다. SMTP 계정/전송 서비스가 자동 구성되는 것은 아니다.
`mail()`이 true여도 로컬 전송 프로그램이 받았다는 뜻이며 실제 수신자 도착을 보장하지 않는다.

### 1. 메일 전송 프로그램/서비스
```bash
php -i | grep -i sendmail_path
command -v sendmail || true
systemctl is-active postfix || true
sudo postqueue -p 2>/dev/null || true
```
PHP-FPM은 CLI와 ini가 다를 수 있으므로, CLI 결과가 정상이어도 PHP-FPM 설정을 확인한다.

### 2. 요청 후 로그 확인
```bash
sudo journalctl -u php8.1-fpm --since '15 minutes ago' --no-pager | grep 'GAME LAB DEVTEST'
sudo tail -n 100 /var/log/mail.log
sudo journalctl -u postfix --since '15 minutes ago' --no-pager
```
Ubuntu PHP-FPM 로그 위치나 Postfix 로그는 서버 설정에 따라 다를 수 있다.
`PHP mail() rejected`: PHP/로컬 MTA 수준의 즉각적인 발송 실패.
`PHP mail() accepted ... delivery unconfirmed`: 로컬 시스템이 요청만 접수함. 실제 전송 결과는 Postfix 큐/메일 로그에서 확인.
`status=deferred`: 배송 지연/실패 재시도 중.
`status=bounced`: 배송 거부/반송.

### 3. 메일 경로 설정
- 웹앱에 SMTP 비밀번호를 하드코딩하거나 Git 저장소에 올리지 않는다.
- 운영 서버에서 발송 계정 인증이 가능한 메일 릴레이 또는 Postfix SMTP 설정을 사용한다.
- `noreply@stimpack.pe.kr` 주소의 실제 발신 권한/SPF/DKIM/DMARC를 점검한다.
- 참여 신청 DB의 `mail_sent_at` 값은 PHP 전송 프로그램이 수락한 시간이며 수신 완료 증거가 아니다.
