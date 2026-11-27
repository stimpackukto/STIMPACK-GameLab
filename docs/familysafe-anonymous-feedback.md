# 우리 가족(FamilySafe) 익명 의견 접수

**배포 순서:** SQL → API → 앱 업데이트.

1. GameLab MySQL 데이터베이스에 `sql/20261128_anonymous_review_rate.sql` 적용
2. `api/anonymous_review.php`를 운영 서버 `/var/www/gamelab/api/`에 배포
3. FamilySafe_Kakao 앱 버전 0.3.25 (versionCode 25) 빌드 및 비공개 테스트 트랙에 배포
4. 앱 상단 '의견 보내기'에서 모달을 열고 '개선 제안' 선택 후 테스트 의견 등록
5. `https://stimpack.pe.kr/apps/?review=pe.kr.forum.familysafe#pe.kr.forum.familysafe`에서 **익명** 의견 및 앱 버전 표시 확인

기존 `/reviews/write.php`의 구글 로그인 방식과 관리자 답글/삭제 기능은 변경하지 않습니다. 등록 전송은 HTTPS JSON POST로 별도 API에서 이뤄집니다. 익명 의견의 이름은 '익명', Google sub/email은 빈 값입니다. 앱의 위치, 가족 목록, 기기 식별자 등은 전송하지 않습니다.

서버 API는 FamilySafe 패키지명 하나만 허용하고, IP 해시별 시간당 5회로 제한합니다. IP 해시는 일별 변경되며 48시간이 지난 레이트 기록은 정리합니다. 이는 테스터 신원 확인 기능이 아닙니다.

**주의:** 익명 의견은 현재 홈페이지 공개 '사용후기'에 노출됩니다. 비밀 신고 또는 개인정보를 입력하지 않도록 안내가 필요합니다.
