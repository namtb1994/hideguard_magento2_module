# Rà soát framework và chất lượng code

Lịch sử kiểm tra trước khi khôi phục ngày 23/10/2026.

Cập nhật: 13/10/2026. Magento Community **2.4.7-p10**, Framework
**103.0.7-p10**, PHP **8.2.34**, Magento Coding Standard **41**.
Module/core hỗ trợ cú pháp PHP 8.1+. Đợt 13/10 thêm verifier Ed25519 v2 vào core và nối vào Magento.

## Dọn dẹp và tối ưu sau khi chuyển sang package

- Xóa thêm `Model/HttpStatus.php` và dependency DI tương ứng. Controller
  Check/Verdict map lỗi core sang HTTP tại luồng execute dùng chung, giữ nguyên
  status và Retry-After; không đưa HTTP vào package độc lập framework.
- Bỏ việc nhận diện Product/Cart/Checkout lặp trong Bootstrap và việc JavaScript
  ghi đè `pageProtected`. PHP PageRules là nơi quyết định trang cần bảo vệ;
  Bootstrap chỉ nhận diện gate riêng. Regression đã tái hiện bản cũ gọi SDK
  dù PHP trả false; bản mới giữ nguyên quyết định PHP.
- Không xuất initializer trên trang ngoài rule khi chỉ chọn Product hoặc không
  chọn action bảo vệ. Cart/Checkout/Complete order vẫn đăng ký hook chờ tương tác
  trên trang công khai. Protected page, custom path và gate vẫn khởi tạo bình thường.
- Khi chỉ dùng custom path/Complete order, PageRules không tra URL Rewrite:
  **1 → 0 lần gọi UrlFinder mỗi lần kiểm tra path không khớp**. Đây là phép đo
  số lần gọi dependency qua test, không phải benchmark thời gian toàn trang.
- Environment/Block Duration/Recheck Interval dùng danh sách giá trị của
  ProtectionPolicy trong package, chỉ giữ nhãn dịch native trong source model.
- Gộp chuẩn hóa textarea path trong Config, dùng chung lúc đọc và validate dữ liệu
  POST. Chỉ bỏ dòng trắng, giữ rule hợp lệ `0`; bản cũ làm mất rule này.
- Bỏ wrapper Config::protects và BrowserIdentity::customerId; caller dùng trực
  tiếp core policy/native session. Access dùng lại một policy cho cùng lần check;
  OrderAccess kiểm tra một grant khi Checkout/Complete order cùng bật (2 → 1),
  StateRepository chỉ validate quote resource một lần. Lượt tiếp giảm ròng
  **22 dòng runtime** (thêm 12, xóa 34), không thêm file runtime; test tăng 54 dòng
  để kiểm tra cấu hình/area/denial và phát hiện kiểm tra lặp.
- Composer archive loại test và tài liệu nội bộ. Source **64 → 63 file**:
  49 file chạy module, 11 file test, 3 tài liệu. Archive **50 file** gồm runtime
  và README; không thay thế việc kiểm soát nội dung ZIP từ công cụ khác.

Policy, AccessService, AccessException, PolicyTest và interaction-lock cũ đã
được xóa ở bước extraction trước đó. State machine và browser tests dùng chung
nằm ở `hachitech/antidetect-php`, không duy trì bản nghiệp vụ riêng trong module.

## Những phần vẫn cần giữ

- Config/source models/system.xml: scope, field admin và nhãn dịch Magento.
- BrowserIdentity, StateRepository, StoreLock: session/customer, bảng cũ và
  transaction/lock native để thực thi contracts của package.
- PageAccess, PrivatePages, VarnishAccess: kiểm tra trước FPC, header private và
  xuất VCL bypass HTML. Gộp hoặc bỏ tùy tiện có thể khiến cache vượt kiểm tra.
- QuoteAccess, OrderAccess, ConsumeGrant: enforce và consume quyền trên nghiệp vụ
  Magento, gồm REST/GraphQL; admin/CLI không áp rule storefront.
- Controller/gate/reset, ACL/form key, observer/cron/schema: lifecycle native.
- RequireJS entry, templates/layout và `_module.less`: UI native; runtime.js và
  runtime.less là asset build/publish từ package, được kiểm tra byte-for-byte.

## Pattern đã đối chiếu

- Phiên bản từ composer.json/composer.lock; module dùng DI và virtual type riêng
  cho Guard, không preference global contracts của package.
- Source model theo `Magento\Framework\Data\OptionSourceInterface` và system.xml;
  các giá trị/label hiện tại giữ nguyên, đối chiếu trực tiếp ProtectionPolicy.
- HTTP action interface, JsonFactory, result headers, form-key validator và
  controller inheritance; Check/Verdict chia sẻ execute, chọn công việc bằng
  phương thức override, không suy đoán từ chuỗi action request.
- PageConfig đặt inert trước render; Template dùng text/x-magento-init,
  AMD/RequireJS và LESS qua theme. Runtime chỉ dùng `page` để nhận diện gate,
  `pageProtected` điều khiển kiểm tra trang; callback action vẫn thuộc adapter.
- PageRules giữ nguyên case cho URL Rewrite/custom path, chuẩn hóa case khi
  so route Magento; miễn gate/check/verdict để tránh vòng lặp.
- Plugin/event/cron, ResourceConnection và LockManager giữ nguyên contract native.
  Integration xác nhận PageAccess chạy trước plugin PageCache.
- Asset cập nhật qua DeployStaticFile theo cách DeployPackage truyền
  area/theme/locale/module; không copy tay asset hoặc thêm thẻ script trực tiếp.

## Kết quả kiểm tra quan sát ngày 09/10

| Kiểm tra | Kết quả |
| --- | --- |
| PHPUnit Magento | 106 test / 254 assertion: PASS |
| Browser core + Magento entry | 39 Node test: PASS |
| Native integration/MySQL | PASS: DI/XSD, cache order, initial lock, Product-only homepage không init, deferred action init, grants/blocks/reset/expiry, legacy state, quote/customer binding, rate limit và HTTP 401 |
| DI compile | PASS; compile lại sau khi bỏ constructor dependency HttpStatus |
| Native asset publication | JS entry đã cập nhật cho Troyclancy/default và Magento/blank en_AU; hash source/served khớp, runtime và style hiện có đạt; không build lại CSS theme trong lần dọn này |
| Cache | config/layout/block_html/full_page đã clean |
| PHPCS Magento2 production PHP/PHTML | 0 lỗi, 1 warning base64_decode đã có từ trước |
| ESLint theo Coding Standard 41 | Frontend/admin entry: PASS |
| Browser publication --check | PASS |
| Composer validate | Hợp lệ; warning chưa khai báo license đã có từ trước |
| Composer archive | Đủ registration/manifest/DI/schema/view/README; 50 file, không kèm Test/AUDIT/plan/lớp đã xóa |

Các regression PHP/JS được chạy trước khi sửa và quan sát thất bại, sau sửa đều
đạt. Integration dùng cấu hình trong tiến trình và bảng MySQL temporary;
không đổi setting cửa hàng, chạy migration hay tạo đơn hàng thật.
Warning base64_decode là do dùng strict decode cho return path; helper URL
Decoder native không cung cấp cùng contract. PHPCS còn phát cảnh báo deprecation
của tooling đang cài, không có lỗi code production trong lần quét này.

Nghiệm thu phần dọn dẹp: **tester PASS, reviewer APPROVED**. Review kiểm tra
trực tiếp diff, callers, core contracts, source framework và test ở trên;
không còn finding material trong phạm vi thay đổi.

## Giới hạn còn lại

- Đã triển khai chữ ký collect Ed25519 v2 theo source Shopify, cần PEM public key
  riêng cho site trong mọi environment. Challenge có chữ ký hợp lệ theo Allow.
  Chưa có signed nonce gắn session hoặc replay store single-use; freshness và
  deadline không ngăn transfer giữa browser trong cửa sổ hợp lệ.
- SDK/site key thật, browser thật và Varnish/CDN đang chạy chưa được kiểm thử
  end-to-end. Test browser dùng DOM/network giả lập.
- Không chạy setup:upgrade lần này. DB local còn schema drift đã biết của
  Mageplaza OSC ở sales_order.osc_survey_question; không sửa trong phạm vi module.
- Chưa diễn tập rollback đầy đủ hoặc publish Composer package. Xem
  [tiến độ package](https://github.com/namtb1994/hideguard-antidetect-php/blob/master/docs/PROGRESS.md).

## Xác minh chữ ký 13/10

Core **164 test / 799 assertion** trên PHP 8.1 và 8.2 với MySQL/HTTP; module unit
**121 test / 281 assertion**; browser **41 test**. Verifier đã kiểm tra với vector
HideGuard thật và Node crypto độc lập; PHPStan mức 8, PSR-12, coding standard
runtime Magento, Composer validate và build/publish asset check đạt. Bản source
registration bị đổi tên được giữ nguyên; test đăng ký module riêng trong process.
Native integration đạt signed Production HTTP, quote binding, HTTP 400/revocation,
DI/XML, cache hook order và HTTP 401; kiểm tra Composer consumer độc lập cũng đạt.

Review đợt chữ ký: **APPROVED**. Đã đối chiếu canonical collect v2/PEM/base64url/
freshness với source Shopify, PSR-4/PHP 8.1, native DI/XML/HTTP/RequireJS Magento
và failure paths thu hồi quyền; tester **PASS** trong phạm vi local đã nêu.


## 2026-10-19 — cửa sổ xác minh ở server

Đã đối chiếu PHP 8.1+ core/PSR contracts và Magento 2.4.7-p10 (DI, HTTP action
interfaces, declarative schema, RequireJS, LESS). Trạng thái/hạn chờ nằm trong
package; Magento chỉ ánh xạ persistence và HTTP/UI. Hai cột bổ sung ở bảng session,
không thêm bảng. Nhóm verdict hiện tại được giữ nguyên.

Kiểm tra: 133 unit tests/322 assertions Magento, native integration với bảng tạm,
51 test browser; core 209/935 trên PHP 8.1 và 8.2, WordPress 37/403, Laravel 10/93.
PHPStan/PSR-12 pass. Magento Coding Standard không có lỗi; cảnh báo `base64_decode`
ở hàm kiểm tra return path có sẵn từ trước. Test luồng gồm block SDK, timeout ở
server không cần JS, retry, chặn grant cũ ở Always kể cả rate limit, callback cũ
và thao tác trong gate. Tester PASS, review APPROVED sau khi sửa race của retry
và fallback reload khi tích hợp chưa có URL gate.

Đã chạy setup:upgrade, compile DI và deploy assets cho Magento/blank,
Troyclancy/default en_AU. HTML preview vẫn là dữ liệu đã gửi xuống browser;
kiểm soát nghiệp vụ phải nằm ở server. Chưa tuyên bố chống replay giữa browser
bằng attempt ID vì ID này không nằm trong chữ ký HideGuard.


Kiểm tra `setup:db:status` toàn hệ thống vẫn báo declarative schema còn chênh lệch.
Dry-run chỉ còn `ALTER TABLE sales_order MODIFY COLUMN osc_survey_question text NULL
COMMENT ""`; không có DDL còn chờ cho Hachitechs_Hideguard. Hai cột xác minh
thực tế đúng kiểu/nullability. Không sửa schema của extension ngoài phạm vi này.

Xác minh asset sau deploy: Publisher native bỏ qua file đích đã tồn tại, nên đã
xóa riêng asset/cache sinh ra của module và styles-m/styles-l ở hai theme rồi
deploy lại bằng CLI Magento. Runtime/access JS ở pub/static khớp SHA-256 source;
runtime.less trong view_preprocessed khớp source, styles-m.css có style Retry.
Đã clean config/layout/block_html/full_page. PHPStan mức 8 cuối cùng không có lỗi.

## 2026-10-19 — thống nhất trang chặn

Tái hiện trên Chrome: GET trong thời gian Block bị trả Page trước native request
processing/action, nên theme chưa được load và asset trỏ vào frontend/_view.
Gate controller được dispatch bình thường có đủ stylesheet của theme.

Theo yêu cầu mới, PageAccess chuyển hướng GET bị chặn bằng native Http/UrlInterface
tới cùng gate với SDK Block; gate action trả 401/Retry-After và khởi tạo theme qua
Magento_Theme LoadDesignPlugin/DesignLoader. Đã bỏ PageFactory và phần đổi route/
render Page trực tiếp. API/AJAX/POST/HEAD vẫn nhận JSON 401, kèm verificationUrl;
không chạy action gốc hoặc kéo dài Block. Không thay đổi core/runtime/nhóm verdict.

Regression redirect đã quan sát FAIL trước sửa, sau sửa toàn bộ 133 unit test/
306 assertions và native integration PASS. Integration xác nhận 302 → gate 401,
no-store, giữ return path/query, gate không redirect lặp và URL stylesheet theo
theme; các assertion Challenge đối chiếu constants hiện tại do người dùng chỉnh.
Magento Coding Standard file sửa không có lỗi; DI compile/cache clean đạt.
Chrome reload đúng URL sản phẩm trong ảnh tự tới gate, dùng Troyclancy/default
styles-m/styles-l; card có position fixed, border-radius 24px và max-width 560px.

Tester PASS; reviewer APPROVED sau đối chiếu diff, PageRules exemption,
Bootstrap return-path validation, native Http redirect, PageFactory/gate action
lifecycle và Magento_Theme LoadDesignPlugin trên Magento 2.4.7-p10/PHP 8.2.

## 2026-10-19 — không lộ nền gate khi hết Block

Pending trước đây làm card bị clip và nền trong suốt; Allow ẩn gate trước khi
navigation hoàn tất. Shared runtime hiện tách displayState của standalone gate
khỏi trạng thái quyền: card pending/nền trắng còn hiển thị khi chờ hoặc sau Allow
cho tới khi document được thay thế. Các trang nội dung vẫn unlock bình thường.
SSR thêm data-page="gate"; layout native bỏ riêng page.main.title. LESS tiếp tục
đi qua _module.less, JS qua build ESM/AMD/RequireJS; không thêm framework dependency.
Marker/asset cũng đồng bộ ở các adapter PHP thuần, WordPress và Laravel.

Hai regression flash quan sát FAIL trước sửa; 52 test frontend và native Magento
integration PASS sau sửa. Laravel đủ MySQL/HTTP đạt 10 tests/93 assertions; template
WordPress/PHP thuần lint đạt (không chạy lại full WordPress HTTP suite). Magento
Coding Standard cho template không có lỗi. Build/publication checks và đối chiếu
served runtime/compiled LESS của hai theme đạt; đã deploy static và clean cache.

Visual QA dùng HTML pending do Magento HTTP render với session mới, tắt script
trong bản preview tạm để quan sát trước AMD: nền rgb(255,255,255), card 560px,
clip-path none, position fixed, không có page-title-wrapper. Giữ gate trong khoảng
Allow → navigation được xác minh bằng browser runtime tests với unload trì hoãn
trong harness, không giả định đã chạy lại luồng HideGuard live tới Allow.
Tester PASS; reviewer APPROVED sau kiểm tra diff, contracts page/whenReady,
normal-page unlock, failure/retry, native layout XML/XSD, PHTML escaping và
source LESS/build/publish. Backend Block/grant/signature và constants giữ nguyên.

## 2026-10-19 — GitHub private VCS

Đã tải probe độc lập từ namtb1994/hideguard-antidetect-php và đối chiếu toàn bộ
src/browser/dist với bản local. Tạo tag v0.1.0 ở commit
462bde7228b87c13f5464b388818719a93335e41; root Composer chuyển path sang VCS,
giữ constraint ^0.1.0 và chỉ resolve hachitech/antidetect-php từ repo này.
Lockfile chỉ đổi package này. ZIP đã giải nén thật vào vendor, không symlink;
Reflection Guard và Composer InstalledVersions xác nhận autoload tại vendor.
Credential có sẵn được sử dụng, không ghi token vào manifest/lockfile.

Composer 2.10.1/PHP 8.2.34: validate đạt (warning root có sẵn), install dry-run
không có operation; module 133 unit tests/306 assertions và native integration
PASS với package GitHub. Hash production PHP/browser dist đều khớp bản local.
Audit còn 3 advisory ở league/flysystem, symfony/http-foundation, symfony/yaml;
các package này không thay đổi. Không có advisory cho hachitech/antidetect-php.
Probe đã xóa; source local vẫn được giữ để phát triển, Magento dùng vendor.
Tester PASS; reviewer APPROVED sau kiểm tra diff theo baseline trước chuyển,
manifest/PSR-4/PHP 8.1, tag/commit/dist provenance và native DI/HTTP contracts.

## 2026-10-19 — cài core qua dependency của module

Root Composer require hachitech/module-antidetect-browser qua path repository
app/code; module require hachitech/antidetect-php ^0.1.0. Core là dependency bắc
cầu từ GitHub private, không còn require trực tiếp ở root hoặc path tới source
core local. Lockfile chỉ thêm module; toàn bộ package đã khóa trước đó giữ nguyên.
Native registration.php/PSR-4 được Composer load, Magento require_once không
đăng ký module trùng. README ghi rõ cấu hình root repositories/authentication và
bước đăng ký module một lần; chỉ copy app/code không tự cài dependency.

Frontend harness đọc AMD/runtime được ship trong module, không import bộ test
phát triển của core. Module copy độc lập ở /tmp đạt 10 frontend tests; Magento
đạt 133 unit tests/306 assertions và native integration. Composer validate đạt
với warning có sẵn; root install dry-run không có operation. Reflection Guard
và InstalledVersions xác nhận core là thư mục vendor thật, không symlink local.

Consumer thử nghiệm chỉ require module, dùng dependency Magento thật, không có
lockfile/vendor/source core ban đầu: composer install tải và cài core v0.1.0 từ
GitHub ZIP. Xóa riêng vendor của consumer rồi install từ lockfile cũng đạt.
Hai lượt xác minh autoload, native module registration, commit/tag và AMD asset
khớp core. Consumer dùng --no-dev --no-scripts --no-plugins để kiểm tra dependency;
native integration và root Composer kiểm tra trong project Magento hiện tại.
Credential chỉ truyền qua môi trường, không ghi vào fixture/manifest/lockfile.

Tester PASS; reviewer APPROVED sau đối chiếu Composer root-only repositories,
path version/symlink, require bắc cầu, PSR-4/registration native và test harness
Node tương thích. Không thay đổi PHP runtime của module, schema, core hoặc các
package mẫu WordPress/Laravel. Các thư mục consumer thử nghiệm đã được dọn.


## 2026-10-20 — verdict API trước action

Module require core ^0.1.2 từ vendor (VerdictClient) và Magento Framework
^103.0.7, đúng stack Magento 2.4.7-p10/PHP 8.2.34 đang chạy. API Key dùng
obscure/Backend Encrypted và TypePool sensitive theo từng environment/scope;
Config chỉ decrypt khi gọi server, không đưa secret vào browser config.

CheckAction::EVENTS chứa các event predispatch checkout/One Step Checkout,
onepage payment/order, cart add, login form/AJAX, register và event payment/order
ở tầng nghiệp vụ. Bổ sung popup create/OAuth login/callback/email của Mageplaza
Social Login theo controller/route đang cài, không phụ thuộc class extension.
Native FrontController validate request trước predispatch;
ActionFlag dừng execute khi bị từ chối. Quote::addProduct/QuoteManagement::submit
cũng kiểm tra trước mutation; admin/cron không gọi API. Quote API chỉ dùng binding
đã được server xác nhận, không nhận visitor ID tùy ý từ client.

ActionVerdict gọi VerdictClient ngoài storage lock, kể cả khi còn pass, rồi dùng
Guard/storage/block policy hiện tại. Repeated hooks dùng kết quả theo
browser/store/customer/request; ResetAfterRequestInterface xóa cache giữa request.
Active Block không gọi API và giữ deadline. API lỗi/khác visitor/malformed thu hồi
grant và chặn action. ActionResponse giữ denial qua các controller/REST/GraphQL
catch exception; DeniedResponse dùng chung gate, 401/Retry-After, JSON hoặc 303
cho form POST. Return chỉ dùng path nội bộ từ referrer cùng host, không replay POST.
AMD access.js dùng ajaxPrefilter/always, nhận cả global:false và chỉ điều hướng
cho response cùng origin có header riêng. Trang không thuộc rule không tự gọi SDK.

Response API unsigned theo JSON do người dùng cung cấp được validate bằng
ApiVerdictVerifier chỉ trong backend API Guard; Ed25519 Guard của endpoint SDK
giữ nguyên. Verifier API dùng thời điểm nhận response qua HTTPS/API key; không
khẳng định response API có chữ ký. Constants verdict và package core không sửa.

Quan sát smoke test ban đầu FAIL do config/metadata cũ và constructor của thư viện
Composer ngoài component Magento không có metadata cần thiết. Đã dùng generated
factory với virtual type client/transport (cùng pattern Guard), clean config và
metadata trước compile. Smoke test sau rebuild sạch xác nhận factory thật dùng
CurlTransport 8s/3s, đúng observer và hai verifier riêng. Không sửa vendor.

Nghiệm thu: 164 unit tests/364 assertions, 14 frontend tests và native integration
PASS. Integration dùng VerdictClient thật/HTTP transport giả lập, Guard thật,
Sodium signatures và MySQL temporary; xác nhận API Block khi còn pass, sticky
Block không thêm request, Allow/binding không gia hạn pass, outage/revoke,
visitor mismatch, unsigned browser rejection, native factory và safe form return.
PHPStan level 8 đạt cho 5 class mới; Magento Coding Standard không có lỗi cho các
file production sửa (Bootstrap còn warning base64_decode có sẵn). DI compile và
static deploy en_AU cho Magento/blank, Troyclancy/default đạt; SHA-256 access.js
đã publish khớp source. Root composer.json và phiên bản core/dependency khác giữ
nguyên; lock chỉ cập nhật manifest module. Chưa gọi HideGuard live với API key thật.

Tester PASS; reviewer APPROVED sau đối chiếu tested diff, native request/event/
ActionFlag lifecycle, generated factory/virtual types/compiled constructor resolver,
Backend Encrypted, PSR contracts, API versus SDK trust boundary và code quality.
Reset interface đối chiếu [source Magento 2.4.7](https://github.com/magento/magento2/blob/2.4.7/lib/internal/Magento/Framework/ObjectManager/ResetAfterRequestInterface.php).
Không có migration hoặc thay đổi package WordPress/Laravel trong lần này.

## 2026-10-20 — debug log cho verdict API

Protection thêm Debug Log Yes/No, mặc định No, hỗ trợ kế thừa Default / Website /
Store View. Config đọc flag riêng, không thêm vào policy hoặc browser settings.
DebugTransport bọc HttpTransport của package qua generated factory theo store ID
của action/quote; không sửa vendor, không đổi timeout 8s/3s hay Guard/block flow.
Logger/handler dùng virtual type Magento Framework Monolog và Handler Base, theo
pattern `Magento_Payment/etc/di.xml`, ghi
`var/log/hachitechs_hideguard_debug.log`.

Mỗi lần gửi API có request và response/error cùng request_id, store, method, URL,
HTTP status và thời gian xử lý. Payload summary chỉ giữ visitor hash, verdict,
score và tối đa 20 reason code; không log header, key, signature, raw visitor,
reason detail, raw body lỗi hoặc exception message. Body quá 64 KiB được bỏ khỏi
summary. Log ghi response nhận từ API trước Guard validation, không khẳng định
verdict đó đã được Guard chấp nhận. Khi tắt không ghi thêm; lỗi config/log I/O
không thay đổi HTTP result, không retry. Block còn hạn/chưa có trusted visitor
không tạo request API. Request SDK trực tiếp từ browser không thuộc log này.

Nghiệm thu: 176 unit tests / 436 assertions PASS; native integration PASS gồm
field Yes/No/default/scope, file log thật, redaction, HTTP 503, block bỏ qua API,
và tắt log vẫn kiểm tra API bình thường. PHPStan level 8 cho DebugTransport và
ActionVerdict PASS sau khi generated factory đã được compile. Magento2 PHPCS
không có lỗi/warning cho 3 class production sửa. Kiểm tra compiled ObjectManager
thật PASS với generated factory/store 7, CurlTransport 8s/3s, handler đúng path,
VerdictClient thật qua transport giả lập, ghi file tạm/redaction/off switch.
DI compile và clean cache config/block_html đạt. Không gọi HideGuard live hoặc
thay đổi cấu hình bật/tắt protection/debug của store trong test.

Tester PASS; reviewer APPROVED sau đối chiếu contracts HttpTransport/HttpResponse,
native Magento logger/factory/DI, scope của action/quote, PHP 8.1+ compatibility,
disabled/error paths, data whitelist/bounds và code quality. Chỉ thêm một class
production; không có migration, sửa JS/LESS hoặc thay đổi dependency.

## 2026-10-23 — khôi phục Hachitechs_Hideguard và package v0.1.5

Khôi phục source đã mất từ các patch/lệnh ghi file trong lịch sử phiên làm việc,
đối chiếu lại bằng bộ test và runtime prebuilt. Thư mục mới
`app/code/Hachitechs/Hideguard`; namespace Magento `Hachitechs\Hideguard` và
registration/ACL/template/DI/AMD asset reference dùng `Hachitechs_Hideguard`.
Không tạo compatibility alias cho namespace module cũ và không sửa WordPress/Laravel.

Root Composer chỉ thêm require `hachitechs/hideguard-php:^0.1.5` cùng VCS repository
`https://github.com/namtb1994/hachitechs-hideguard-php.git`, giới hạn bằng `only`.
Không có require module, path repository, module symlink trong vendor hoặc
composer.json riêng của module. Magento phát hiện registration.php bằng
NonComposerComponentRegistration/registration_globlist và autoload app/code native.
Package thực sự cài trong `vendor/hachitechs/hideguard-php`, tag v0.1.5, commit
`c27a277f60ee21d2a15debc7ea52338a4229d98f`. Namespace package đã đổi thành
`Hachitechs\HideguardPhp`: source, DI, generated factory references và test đều
đã cập nhật, không còn import `Hachitech\Antidetect`. Không đổi phiên bản các
dependency khác. Đối chiếu source tags v0.1.4/v0.1.5 xác nhận PHP contracts không
đổi ngoài namespace; AMD/LESS prebuilt có nội dung giữ nguyên.

Giữ config section `hachitech_antidetectbrowser`, ba bảng `hachitech_antidetect_*`,
route `/antidetectbrowser/*`, lock/hash và shared runtime identifiers để tái sử
dụng 11 setting hiện có, block/session và các URL cũ. Không sửa dữ liệu/key của
store. Tên hiển thị admin là Hachitechs → HideGuard. Debug log chuyển tới
`var/log/hachitechs_hideguard_debug.log`; giữ nguyên default off/redaction.

Tester PASS: 176 unit tests / 436 assertions và 14 frontend tests PASS với v0.1.5.
Native integration PASS: chữ ký Sodium, store scope/fields, deadline, grant/block,
quote binding, cache plugin order, HTTP/gate, API errors và debug log thật/off
switch, dùng DB temporary và fixture HTTP. PHPStan level 8 cho 6 class trọng yếu
PASS. PHPCS toàn PHP/template production không có error; còn một warning đã có
ở Bootstrap do strict base64_decode cho return path, các kiểm tra return an toàn
vẫn giữ nguyên. Composer validate PASS với warning cấu hình root đã có.

DI compile PASS sau khi clean config và bỏ compiled metadata cũ; smoke trên
ObjectManager compiled thực tế xác nhận module app/code đang bật, core autoload
từ vendor mới, factory/transport 8s/3s, verifier SDK/API riêng, logger và ACL/asset
module mới. Static frontend en_AU của Magento/blank và Troyclancy/default, cùng
adminhtml Magento/backend en_US đã deploy. JS access/runtime đang publish khớp
source; runtime AMD/LESS source khớp package v0.1.5. Chưa gọi HideGuard live.

Schema comparison chỉ đọc không có thay đổi ở bảng module; database còn một
sai lệch có sẵn ngoài phạm vi ở `sales_order.osc_survey_question` (modify_column).
Không chạy setup:upgrade toàn hệ thống để tránh sửa schema extension khác trong
lần phục hồi này. Module không có setup_version/patch mới; bảng hiện tại đầy đủ.
Project mới vẫn chạy setup:upgrade theo README để tạo các bảng lần đầu.

Reviewer APPROVED sau đối chiếu Magento 2.4.7-p10/PHP 8.2.35, package manifest /
contracts v0.1.5, native app/code discovery, scope/config/storage compatibility,
generated factories/compiled DI, frontend asset references và tested behavior.
Không phát hiện defect material hoặc vi phạm pattern/code quality trong phần
khôi phục/đổi namespace. Source module vẫn cần được đưa vào Git và commit cùng
composer.json/lock; archive phục hồi riêng được tạo ngoài working tree.


## 2026-10-26 — hoàn tất phạm vi conformance trong module

Tiếp tục từ bản source đang sửa dở của phiên trước; so sánh với snapshot trước
thay đổi tại `/tmp/hideguard-20261026-before/Hideguard`. Giữ nguyên constraint
Composer theo yêu cầu, runtime đã cài v0.1.7. Không sửa package/vendor hoặc các
module WordPress/Laravel, không chạy migration hay đổi key/settings cửa hàng.

### Phạm vi và pattern đã kiểm tra

Magento 2.4.7-p10 / PHP 8.2; dùng constructor DI, interface service plugins,
event observer, generated factory/virtual type và ResetAfterRequestInterface.
Đối chiếu trực tiếp AccountManagementInterface/AccountManagement,
CustomerTokenService, CustomerGraphQl GenerateCustomerToken/CreateCustomerAccount,
webapi.xml của Customer/Integration, QuoteManagement::placeOrder/submit/submitQuote,
CartMutex, Checkout Session::getQuote, native VclGenerator và DeployStaticFile.
Browser tiếp tục dùng AMD/RequireJS, native history/fetch và giao thức denial hiện có;
runtime shared từ package được giữ nguyên. Đối chiếu cú pháp regex/chuỗi VCL với
[Varnish VCL Syntax](https://www.varnish.org/docs/users-guide/vcl-syntax/).

| Phần | Kết quả trong phạm vi local |
| --- | --- |
| Order/payment | Chặn ở submit quote, trước native order/payment callback. Block, outage, schema sai và visitor mismatch đều từ chối. Không thêm hook capture/invoice/pay/webhook; bỏ payment-import hook theo phạm vi user đã chốt. |
| Login/register | Interface plugins cho authenticate/createAccount/createAccountWithPasswordHash và customer token service. REST/GraphQL native gọi các service này. Admin/cron giữ ngoại lệ; headless phải có session/binding hợp lệ. |
| Form | Contact form và POST tùy biến khớp PageRules kiểm tra mới trước controller. Custom form ngoài rule phải khai báo đường dẫn POST. |
| Attempt/dedup | Kết quả chỉ tồn tại trong cùng invocation/action/resource/store/browser/actor/policy. Nested hook inspect grant hiện tại; invocation tiếp theo hoặc quote/action khác gọi API mới. Denial vẫn được giữ nếu controller bắt exception. |
| Revocation/consume/concurrency | Revoke khi API in flight không cho response cũ mở order/payment. Consume hủy khả năng dùng lại verdict trong invocation. Native CartMutex với process thứ hai từ chối vào callback đồng thời. Transaction protection độc lập giữ block sau rollback checkout. |
| SPA/fetch | Đổi path/query qua history khóa và reload để server quyết định policy mới; hash-only giữ trang. Fetch cùng origin nhận denial marker chuyển gate, clone giữ body cho caller. |
| Cache | VCL theo enabled store, secure/unsecure host và base path. Custom-only rule thu hẹp bypass; native points giữ bypass toàn store để bao phủ mutable URL Rewrite. PURGE/static/media giữ native. Sửa constant UrlInterface, PCRE escaping và URL secure/unsecure tường minh. |
| Page-only | Không tạo/load cart khi chưa có quote ID; không bind quote hết hiệu lực/chưa persisted. Existing cart vẫn bind sau Allow. |
| Chữ ký | 41 shared fixture vectors đều đạt ở PHP Ed25519Verifier và Node verifyCollect; không gọi upstream live. |

### Kiểm tra đã quan sát

- PHPUnit toàn module: **259 tests / 591 assertions PASS**. Sau điều chỉnh chọn
  URL unsecure tường minh, chạy lại **26 Varnish tests / 54 assertions PASS**.
- Frontend: **18 tests PASS**; ESLint theo Magento Coding Standard và syntax check PASS.
- Integration: **PASS**, Guard/client thật với HTTP fixture và MySQL temporary,
  gồm order denial, rollback, revoke in flight, consume, exact auth plugin resolution,
  native mutex process và các regression DI/XSD/signature/block/grant/customer/rate.
- PHPStan level 8 cho 7 class production thay đổi: PASS; kiểm tra lại endpoint sau
  sửa stale quote PASS. Magento2 PHPCS cho production diff không có error/warning;
  tooling phát deprecation riêng, không thay đổi dependency để xử lý chúng.
- DI compile và smoke compiled ObjectManager: PASS, auth plugins/Varnish constructor,
  client/transport 8s/3s, verifier SDK/private tách riêng, ACL và policy đã lưu.
- Static frontend en_AU Magento/blank và Troyclancy/default deploy PASS; publish
  lại access.js qua DeployStaticFile và xác nhận SHA-256 source/2 theme khớp.
  Cache config/layout/block_html/full_page đã clean.
- Xuất VCL native thành `var/hideguard-scopes-20261026.vcl` PASS; chưa nạp vào proxy.

Đo storage trên page đã Allow với bảng temporary, mỗi nhánh 20 mẫu sau 3 warmup,
so sánh preflight+bind cũ và preflight-only mới (không gồm tạo/load quote hoặc SDK/API):

| Lần đo | Trước p50 / p95 | Sau p50 / p95 |
| --- | --- | --- |
| 1 | 1.565 / 2.283 ms | 0.938 / 1.013 ms |
| 2 | 1.207 / 1.459 ms | 0.700 / 0.888 ms |

Đây là phép đo storage cô lập, không suy ra latency production hoặc tỷ lệ cải
thiện end-to-end. Bộ đo giữ nguyên rate limit; không gửi request HideGuard live.

Tester: **PASS** trong phạm vi implementation local trên. Reviewer: **APPROVED**
sau kiểm tra diff/callers/contracts/pattern/framework source và failure paths,
bao gồm sửa phát hiện VCL escaping, constant, secure URL và stale quote. Các phase
được thực hiện lần lượt trong cùng agent theo workflow của project.

### Giới hạn nghiệm thu còn lại

- Proof chưa có nonce/session/resource do HideGuard ký; module không tự thêm contract
  audience hoặc cơ chế chống replay khác với package. Chưa thể khẳng định chống
  chuyển proof giữa browser trong cửa sổ còn hạn; cần thay đổi upstream để khắc phục.
- HideGuard thực thi key–domain theo xác nhận owner; chưa chạy live đúng/sai domain,
  browser thật, login/register, checkout/payment gateway hoặc CDN/Varnish đang chạy.
  Chưa có VCL compiler/runtime trong local stack; kiểm tra VCL ở mức exporter/PCRE
  và native generation. Khi đổi scope/store/base URL, sinh lại/nạp VCL và purge
  cache trước khi áp dụng policy mới.
- Native mutex bảo vệ placeOrder; không khẳng định mọi custom gateway/đường gọi trực
  tiếp submit hoặc callback có idempotency hay transaction atomic với HideGuard.
  Không hook các bước thanh toán sau khi order đã được chấp nhận, đúng yêu cầu user.
- Không có schema mới; không chạy setup:upgrade toàn hệ thống. Root constraint giữ
  nguyên có chủ đích; cần chốt lại version/lock khi phát hành public 1.0.

Các giới hạn này không được gắn nhãn PASS conformance/live; review APPROVED chỉ
áp dụng phần code và bằng chứng local đã ghi ở trên.

## 2026-10-27 — sửa vòng lặp reload khi đã ở gate

Nguyên nhân được xác nhận trước khi sửa production: gate vẫn tải AJAX nền
`customer/section/load` và Meta Pixel. Khi All pages đang chặn browser, các request
này nhận 401 + `X-Hachitech-Antidetect: denied`. Adapter AJAX/fetch gọi lại
location.replace(gateUrl) ngay cả khi đang ở gate. Core runtime đã có guard cho
trường hợp này, nhưng listener adapter đi ngoài luồng core. Nginx 02:14:40–02:15:45
UTC ghi nhận 55 GET gate/401 và các request AJAX lặp lại. Hai regression test mới
thất bại trên source cũ, tái hiện redirect thừa cho cả jQuery và fetch.

Chỉ thay đổi hai điều kiện listener trong `view/frontend/web/js/access.js` để
không xử lý chuyển hướng action denial khi config.page là gate. Giá trị page do
Block/Bootstrap nhận diện từ full action Magento. Backend tiếp tục trả 401 cho
request bị chặn; runtime gate vẫn giữ trạng thái block, timer hết hạn và retry.
Không sửa PHP/package/shared runtime, policy, TTL, dữ liệu block hoặc key.

Tester PASS: 21 frontend tests (gồm AJAX/fetch gate, giữ timer và chuyển về trang
sau backend Allow, failure gate không bị inert), syntax check và ESLint Magento
Coding Standard. Kiểm tra Chrome trên chính tab đang bị block: adapter version
mới được load, thông báo block ổn định, body không inert. Nginx sau reload thủ công
chỉ có 1 GET gate và 1 lượt mỗi AJAX nền vẫn trả 401, không còn chuỗi reload.
Native DeployStaticFile publish cho hai theme en_AU; SHA-256 khớp source; cập nhật
static version bằng StorageInterface và clean layout/block_html/full_page.

Reviewer APPROVED sau đối chiếu diff với Bootstrap, PageRules/PageAccess,
DeniedResponse, core navigateToGate/acceptBlocked và AMD/RequireJS/jQuery/fetch
pattern hiện có. Không mở quyền backend hoặc thay đổi điều hướng denial trên
trang storefront. Không chạy lại PHPUnit/DI compile vì không sửa PHP/XML.

## 2026-10-27 — miễn thao tác gate khỏi All pages

SIMPLE_TASK: thêm ngoại lệ config.page === gate trong callback isProtectedAction
của AMD adapter. Theo PageRules hiện có, backend đã miễn gate/check/verdict/fail
trước rule all_pages. Frontend nay cũng không coi nút Retry trên gate là thao tác
storefront cần chặn; Retry có thể gửi retry=1 qua endpoint native. Không sửa core
runtime, policy hoặc persistence; backend vẫn xác minh trước khi cấp quyền.

Regression Retry thất bại trước sửa (event bị stopImmediatePropagation), sau sửa
22 frontend tests và 11 PageRules unit tests / 41 assertions PASS. ESLint Magento
Coding Standard và syntax check PASS. Fixture mô phỏng closest/matches của nút
Retry theo DOM thực. Runtime vẫn chờ response Allow rồi mới điều hướng về trang.

Publish bằng DeployStaticFile cho Magento/blank và Troyclancy/default en_AU,
SHA-256 asset khớp source; đổi static deployment version và clean layout/block_html/
full_page. Chrome đã reload gate mới; kiểm tra bấm Retry trên browser bị ngắt do
kết nối Chrome không khả dụng, nên chưa xác nhận bước live đó trong lượt này.

## 2026-10-27 — vòng lặp trang chủ/gate dù SDK trả Allow

CODING_TASK, thực hiện analyzer → coder → tester → reviewer trong cùng agent.
Nguồn Magento 2.4.7-p10/PHP 8.2 và AMD/jQuery/fetch được đối chiếu lại với
PageAccess, Access::status, Guard::preflight, AccessDecision và shared runtime.
Không đổi PHP/package/policy/TTL; giữ Always (pass_duration=0).

Nguyên nhân được xác nhận bằng log và regression trước sửa: gate nhận SDK verdict
200 rồi về homepage. GET homepage chạy Always preflight, thu hồi pass cũ và mở lượt
mới. AJAX nền Meta Pixel/Customer Data nhận 403 verification_required khi SDK của
homepage chưa hoàn thành. Adapter denial chuyển về gate, làm đứt lượt xác minh trên
homepage. Chuỗi lặp 1–2 giây/lần; exception.log lúc 02:52:39.840497 UTC ghi
HideGuard page access check failed / Too many access requests. Rate limit trong
Always thu hồi pass và lưu deadline hiện tại, dẫn đến verification_failed / 403.
Đó là nguyên nhân bị giữ hẳn ở lỗi sau vòng lặp, không phải verdict Block mới.

Sửa ở view/frontend/web/js/access.js: chụp trạng thái pending của trang được bảo vệ
ngay lúc mỗi request AJAX/fetch bắt đầu. Nếu response đó chỉ là verification_required,
không điều hướng hoặc thêm inert cho body; runtime hiện có tiếp tục khóa trang và
chờ SDK/backend. Snapshot giữ đúng hành vi cả khi response nền đến muộn sau Allow.
Backend vẫn từ chối request đó; frontend không cấp quyền hay replay nghiệp vụ.
Block, verification_failed, lỗi API hoặc denial của request bắt đầu sau Allow vẫn
khóa/chuyển gate như trước. Gate exemption và Retry từ bản sửa trước được giữ.

Hai regression homepage/AJAX và fetch thất bại trên source cũ. Tester PASS sau sửa:
27 frontend tests, gồm response đến muộn, unlock sau Allow, Block/error khi pending,
Retry, gate và các luồng storefront; 15 PageAccess unit tests / 38 assertions PASS;
ESLint Magento Coding Standard, syntax và diff checks PASS. Fixture AJAX được mở
rộng để tách thời điểm khởi tạo và hoàn tất, mô phỏng race thực tế.

Native DeployStaticFile publish hai theme en_AU, SHA-256 khớp source; cập nhật static
version và clean layout/block_html/full_page. Dùng chính session đang lỗi, reload
gate và click Retry trên Chrome: POST check 200 lúc 02:58:53, SDK verdict 200 lúc
02:58:55, homepage GET 200 cùng giây, homepage SDK verdict 200 lúc 02:58:57; AJAX nền
vẫn 403 nhưng không phát sinh GET gate tiếp trong cửa sổ log quan sát. Guard.inspect
chỉ đọc sau đó trả allowed, policy fingerprint khớp, pass_duration vẫn 0. Không reset
block list hoặc chỉnh session bằng SQL. Kiểm tra DOM/screenshot cuối không hoàn tất
vì Chrome debugger mất kết nối; không tuyên bố có ảnh xác nhận mở khóa browser.

Reviewer APPROVED sau kiểm tra tested diff, origin/denial marker filtering,
request-time snapshot, body inert ownership, pending versus error/block semantics,
server access checks và các test hậu Allow. Không mở quyền mới ở frontend/backend
hoặc bỏ kiểm tra Always. Nghiệm thu trong phạm vi test và HTTP/backend live đã nêu.
# 2026-10-27 — General request barrier during page verification

Scope: queue same-origin backend XHR/fetch before widgets initialize, retaining
normal HTML/component/static loading. Snapshot before this change is at
`/tmp/hideguard-request-gate-20261027/before`; the module is currently untracked,
so this comparison distinguishes this task from previous work.

Pattern evidence: Magento 2.4.7-p10/PHP 8.2; native Theme `root.phtml` renders the
`require.js` block before head assets. The wrapper composes Theme's original
`page/js/require_js.phtml`, uses SecureHtmlRenderer, constructor DI with the existing
Config/PageRules services, and the native RequireJS mixin pipeline for jQuery.
Magento Theme's URL Signature plugin appends a deployment version to static roots;
the early config removes that suffix for asset classification. No widget selectors,
business endpoint lists, shared-runtime edits, policy changes or new storefront
package dependencies were introduced.

Implemented: early async XHR/fetch barrier; backend Allow releases once; failures,
navigation and abort cancel; 45-second fallback fails closed. Fetch uses native
Request snapshots and signals; XHR retains native instances, response handling,
headers and caller settings, snapshots mutable bodies, and supports abort/reopen.
jQuery retains its jqXHR/Deferred/callback flow with timeout starting at release.
Static/media GET/HEAD, external requests and verification endpoints bypass waiting.
Synchronous application XHR while pending throws rather than blocking verification.

Tester PASS for changed behavior:
- 23 new transport tests with jsdom 26.1.0, actual Magento jQuery and local HTTP XHR:
  arbitrary endpoints, native runtime backend Allow/Block/error, exemptions, native
  Request and FormData snapshots, headers, AbortSignal, XHR events/reuse/reentrant
  abort, timeouts, deadline and callback-error isolation.
- Existing 27 runtime/adapter regressions and 259 backend unit tests/591 assertions pass.
- Targeted native integration passes DI, XML/XSD, template order, original RequireJS
  variables, routing-only public config, disabled/unprotected/gate scope.
- Magento ESLint and PHP/template PHPCS pass (tool deprecation notices only).
- Native DI compile and static deployment for Magento/blank and Troyclancy/default
  en_AU pass; published request-gate.js source hashes match both themes.

Broader integration limitation: the full `local.php` run fails the existing
`Challenge must pass current policy` assertion. Running the pre-change fixture
against the same runtime reproduces that exact failure at its original line 776;
the request-barrier target passes. This task leaves that separate fixture/policy
expectation unchanged.

Live evidence (Asia/Ho_Chi_Minh): before deployment, Instagram feed returned 403 at
10:57:06 before the verdict at 10:57:07. After deployment, native storefront loaded
the early barrier, verdict completed at 11:01:56 and feed returned 200 at 11:01:57.
Chrome control disconnected after Retry, so final visual rendering of images was
not independently captured; server access logs establish request ordering/status.

Reviewer APPROVED for this tested scope: native stack, Theme composition, DI/CSP,
RequireJS conventions, server authority, queue settlement, body snapshots, failures
and scope were inspected. Review found and corrected reentrant XHR abort resetting
a replacement request and callback exceptions interrupting queue settlement; the
affected tests and lint pass after those fixes. Non-XHR/fetch transports remain
outside scope, as documented in README.
