STIMPACK GAME LAB 초기 PHP 프로토타입
===========================

목적
- forum.pe.kr을 먼저 /var/www/gamelab 로 연결해 STIMPACK GAME LAB을 개발
- 현재 운영 중인 stimpack.pe.kr WOW 사이트는 건드리지 않음
- STIMPACK GAME LAB 완성 후 stimpack.pe.kr을 새 포털로 전환

필요 환경
- Nginx
- PHP 8.1 이상
- DB 사용 안 함 (현재는 PHP 배열 기반)

배치
1. 이 ZIP의 gamelab 폴더 전체를 /var/www/gamelab 로 업로드
2. forum.pe.kr의 Nginx root를 /var/www/gamelab 로 변경
3. 기존 PHP-FPM 설정은 그대로 유지
4. nginx -t 후 reload
5. https://forum.pe.kr 접속 확인

중요 설정
- includes/config.php
  WOW_URL        : 현재 WOW 사이트 주소
  CRIMESCENE_URL : 크라임씬 주소
  REDDIT_URL     : 나중에 Reddit 커뮤니티 주소

현재 페이지
/
 /games/
 /apps/
 /test/
 /devlog/
 /community/
 /privacy.php
 /terms.php
 /contact.php
 /api/me.php

주의
- 이 패키지는 STIMPACK GAME LAB 레이아웃을 실제 PHP에서 수정하기 위한 1차 기준본입니다.
- 로그인/DB/게시물 작성/외부 개발자 등록 기능은 아직 넣지 않았습니다.
- Google Auth와 게임 계정 연동은 레이아웃 확정 후 추가하는 것이 안전합니다.
