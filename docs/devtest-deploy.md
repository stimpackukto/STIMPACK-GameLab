# STIMPACK GAME LAB 자체 개발테스트 센터

URL: /test/ (기존 헤더의 '개발테스트' 메뉴)

## 기능
- 기존 GameLab 구글 인증 세션에서 검증된 Google sub 사용
- '모집 중'에서 앱명, 설명/테스트 목적, 모집 완료 인원, 목표 모집 인원 등록
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
   SQL은 CREATE TABLE IF NOT EXISTS를 사용하므로 기존 GameLab/WoW 테이블 변경 없음.
4. 새 test/index.php, includes/devtest.php, 변경된 auth/google_login.php,
   auth/google_callback.php 등을 기존 PHP 배포 방식대로 배포.
   /etc/stimpack/google_oauth.php, includes/config.php 및 기존 PHP-FPM 설정은 변경 금지.
5. 브라우저에서 /test/ 방문 후 구글 로그인(로그인 후 /test/ 복귀),
   앱 등록/모집 인원 수정/상태 이동/재등록을 테스트.

## 추천 점검 시나리오
1. 비로그인: 로그인 유도, 앱 등록/상태 변경 불가
2. 첫 번째 Google 계정: 앱 A 등록 → 두 번째 앱 등록 불가
3. 두 번째 Google 계정: 앱 A의 '테스트 진행'/'완료' 버튼 없음. POST 위조로도 상태 변경 불가
4. A 소유자: 모집 중 → 테스트 중 → 완료, 역방향 변경 불가
5. 완료 처리 후 A 소유자: 앱 B 등록 가능. 완료된 앱 A 기록 유지
6. CSRF 누락·불일치, 임의 status·id·마이너스 모집 인원 등 거부
7. 동시에 2개의 등록 POST를 보내도 고유키로 한 개만 허용

## 주의
- GitHub 반영만으로는 운영 DB가 생성되지 않는다. 위 SQL 배포 전에는 신규 페이지에 준비 중 안내가 표시된다.
- 사용자의 실제 앱 설치, 기기 사용 여부, 테스터 인증/피드백 검증 기능은 이번 단계에 구현하지 않았다.
- 서버에 미추적 test/index.php가 존재하면 Git pull 과정에서 untracked file 충돌이 발생할 수 있다. 백업 후 충돌 파일을 옮기고 Pull 한다.
