# GameLab 웹게임 플레이 시도 집계

## 동작
- 로그인 없이 누구나 홈페이지의 '게임 시작' 또는 웹게임 목록의 '▶ 게임 시작' 링크를 클릭하면 서버로 +1 집계 요청을 전송한다.
- 실제 링크는 /games/{실제 폴더명}/ 로 이동한다. 홈페이지는 더 이상 /games/#게임ID 만 열지 않는다.
- 공통 assets/js/app.js 는 게임 경로에 대문자가 포함되어도 소문자 카운터 키로 변환한다.
- api/game_click.php 는 대소문자에 민감한 /games/{소문자}/ 폴더 확인 대신, 등록된 게임 catalog URL로 요청을 검증한다.
- 집계는 동시 요청에 안전한 MySQL INSERT ... ON DUPLICATE KEY UPDATE 방식.
- 기존 data/game_clicks.json 을 삭제하거나 덮어쓰지 않고 **기존 카운트 + 신규 DB 카운트**로 표시한다.
- 카드가 API 조회에 실패하면 0회가 아니라 '집계 확인 불가'로 표시한다.
- 로그인/Play Store/광고는 집계와 무관하다.
- 새 게임 실행 링크가 별도 정적 HTML 내부에 직접 있거나 외부 사이트에서 게임 URL을 바로 연 경우에는 공통 스크립트가 실행되지 않을 수 있다. 이 경우 각 게임 시작 페이지에도 집계를 연결해야 한다.

## 배포 순서
**SQL을 먼저** 적용한다. 이미 있는 게임, 사용자, 앱, 기존 카탈로그/누적 JSON은 변경하지 않는다.

```bash
cd /var/www/gamelab
mysql gamelab < sql/20261128_game_play_counts.sql
```

이후 GitHub 최신 3개 파일을 업로드한다.

- `api/game_click.php`
- `assets/js/app.js`
- `index.php`

`games/index.php`는 현재 GitHub 추적 대상이 아니므로 직접 수정하지 않았다. 하지만 /games/ 목록이 공통 `includes/header.php`를 사용한다면 최신 `assets/js/app.js`만으로 그 페이지의 게임 시작 링크도 집계된다.

## 서버 점검
1. 브라우저에서 `https://stimpack.pe.kr/api/game_click.php`에 접속 → `{"ok":true,"counts":{...}}` 응답 확인. 503이면 SQL/DB 접속을 우선 점검.
2. `/games/` 또는 홈에서 등록 게임을 1회 실행. 다른 로그인 계정 또는 비로그인에서도 별도 로그인 절차 없이 가능해야 한다.
3. 게임 목록으로 돌아와 새로고침. 해당 게임의 플레이 횟수가 1 증가했는지 확인.
4. DB 조회(참고: 이 수치는 새 집계만 나타남. 웹페이지 총계는 JSON 기존 값+DB 값):
   ```sql
   SELECT slug, play_count FROM gamelab_game_play_counts ORDER BY slug;
   ```
5. 서버 에러 로그의 `[GAME LAB GAME CLICK]` 를 검색하면 API/DB 오류를 확인할 수 있다.
6. 브라우저 개발자도구 Network에서 `game_click.php` POST 요청이 200인지 확인한다.

**주의:** 페이지 이동 시 POST가 브라우저의 sendBeacon/keepalive로 처리되므로, 새로고침 직후/네트워크 상황에 따라 카운트 반영 시점에 약간 차이가 날 수 있다. 고유 사용자 수가 아니라 실행 시도 횟수다.
