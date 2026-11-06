# Hachitechs_Hideguard

Module Magento 2.4.7 trở lên chuyển các setting và luồng kiểm soát truy cập từ app
`/Users/sspro/projects/shopify_app/hidemium-antidetect-browser`.

Module mặc định **tắt**. Khi bật, lựa chọn mặc định là **All storefront pages**
và **Complete order**. Không yêu cầu khách đăng nhập.

## Cài đặt

Module nằm tại `app/code/Hachitechs/Hideguard`, đăng ký native qua
`registration.php` và autoload `app/code` của Magento. Không thêm module vào
`require`, không khai báo path repository và không cần Composer manifest riêng
trong module. Yêu cầu Magento 2.4.7+, PHP 8.1+, ext-curl và ext-sodium.

Project hiện giữ constraint `hachitechs/hideguard-php:^0.1.5` theo yêu cầu trước
đợt phát hành public 1.0. Lockfile và các thay đổi dưới đây dùng **v0.1.7**;
không downgrade về bản thiếu PrivateVerdictChecker/API thu hồi verification.
Package được Composer cài vào `vendor/hachitechs/hideguard-php`. Từ tag v0.1.5,
namespace PHP là `Hachitechs\HideguardPhp`; đây là namespace của thư viện,
khác namespace Magento `Hachitechs\Hideguard`.

Thêm repository sau vào `repositories` của composer.json gốc, giữ nguyên các
repository Magento/extension đang có:

```json
{
    "type": "vcs",
    "url": "https://github.com/namtb1994/hachitechs-hideguard-php.git",
    "only": ["hachitechs/hideguard-php"]
}
```

Mỗi máy/server/CI cần credential GitHub có quyền đọc repo private. Cấu hình auth
riêng cho Composer hoặc SSH; không commit token. Từ root Magento chạy:

```bash
composer require hachitechs/hideguard-php:^0.1.5
bin/magento module:enable Hachitechs_Hideguard
bin/magento setup:upgrade
bin/magento setup:di:compile
bin/magento setup:static-content:deploy -f en_AU
bin/magento cache:clean
```

Commit source module cùng composer.json và composer.lock. Trên project mới,
`composer install` tải thư viện theo lock; Magento tự phát hiện module trong
app/code. Deploy locale/theme của project tương ứng. Với Docker hiện tại, chạy
các lệnh PHP/Composer trong container `troyclancy-php`.

Vào **Stores → Configuration → Hachitechs → HideGuard → Protection**.
Các nhóm key nằm ngay dưới **Environment**; chỉ nhóm của environment đang chọn
hiển thị. Có HideGuard URL, SDK Public Key, server API Key và Signature Public Key.
Module mặc định tắt; điền key, chọn rule rồi bật Enable và lưu.

## Tương thích dữ liệu cũ

Tên module/namespace Magento đã đổi thành `Hachitechs_Hideguard` /
`Hachitechs\Hideguard`. Giữ nguyên config section `hachitech_antidetectbrowser`,
các bảng `hachitech_antidetect_session`, `hachitech_antidetect_block`,
`hachitech_antidetect_rate` và route `/antidetectbrowser/*` để dùng lại cấu hình,
block và URL gate đã có. Không đổi encryption key hoặc chép key ra frontend.
SDK runtime/LESS prebuilt lấy từ package; shared DOM/CSS identifiers giữ nguyên.
Không bật đồng thời module cũ và module mới vì dùng chung storage và config.

## Setting và rule

| Setting/rule | Hành vi |
| --- | --- |
| Enable | Bật/tắt bảo vệ theo Default / Website / Store View. |
| Environment | Development, Staging, Production; mỗi môi trường có bộ key độc lập. |
| HideGuard URL | HTTPS origin riêng cho mỗi environment, mặc định `https://hideguard.io`. Dùng cho cả SDK và request của SDK; có thể thêm port, không kèm path, credentials, query hoặc fragment. |
| Public Key | Site key của SDK; chỉ key của môi trường đang chọn được đưa ra storefront. |
| API Key | Secret gọi verdict API phía server, mã hóa theo environment/store scope; không đưa ra frontend. Bắt buộc cho các action được kiểm tra. |
| Signature Public Key | Ed25519 PEM public key của site; chỉ lưu phía server. Bắt buộc ở environment đang chọn khi bật protection, kể cả Development. |
| All storefront pages | Kiểm tra mọi request frontend trước router và full-page cache, gồm homepage, CMS, category, customer và các route tùy biến. GET đang chờ xác minh hiển thị trang dưới lớp khóa trong suốt; user đã bị chặn nhận HTTP 401. Đồng thời kiểm tra thêm giỏ hàng và đặt đơn phía server. |
| Product | Bảo vệ trang sản phẩm, gồm URL SEO qua Magento URL Rewrite. |
| Cart | Bảo vệ các route giỏ hàng và thao tác `Quote::addProduct`, kể cả REST/GraphQL. |
| Checkout | Bảo vệ trang checkout chuẩn/One Step Checkout và kiểm tra trước khi submit quote. |
| Complete order | Kiểm tra trước khi submit quote; có thể mở checkout nếu các lựa chọn khác không bảo vệ trang đó. |
| Additional Protected Paths | Mỗi dòng một đường dẫn storefront; hỗ trợ wildcard `*`, `customer/*`, `contact`, `category/*`. Không gồm query string; tối đa 50 rule / 4096 byte. |
| Block Duration | 15 phút / 1 giờ / 6 giờ / 24 giờ. Report Block lặp lại không kéo dài block đang có. |
| HideGuard Recheck Interval | Luôn luôn / 1 phút / 5 phút / 15 phút / 1 giờ. Mặc định 5 phút. Các mốc thời gian là thời hạn pass tính từ khi backend xác nhận; trong thời hạn này page check không gọi lại SDK. Action nghiệp vụ vẫn kiểm tra private API mới. Luôn luôn kiểm tra trên mỗi lần tải trang được bảo vệ. |
| Debug Log | Yes/No, mặc định No, kế thừa theo Default / Website / Store View. Ghi request/response của verdict API backend vào `var/log/hachitechs_hideguard_debug.log`. |
| Reset block list | Xóa block trong scope đang chọn, giữ setting, session và grant chưa bị thu hồi. Default Config áp dụng tất cả store view. Yêu cầu ACL reset riêng và form key. |

SDK chỉ tự chạy trên trang khớp access point hoặc Additional Protected Paths.
Chỉ chọn Product thì homepage/CMS không gọi preflight hay SDK khi mới tải trang.
Các action payment, checkout, tạo order, thêm sản phẩm, login và register được
kiểm tra độc lập với danh sách trang khi module bật; chưa có visitor hợp lệ thì
chỉ bắt đầu SDK khi người dùng thao tác hoặc truy cập gate. Save setting thu hồi grant trong scope bị
ảnh hưởng và xóa full-page cache để frontend dùng policy/key mới.

## Verdict API trước action

Khi module bật, backend gọi `Hachitechs\HideguardPhp\VerdictClient` trước payment,
checkout, tạo order, add-to-cart, login và register, kể cả khi visitor còn pass.
Nhập **API Key** trong group key của environment đang chọn, dưới Protection.
Đây là key server của HideGuard, khác Public Key SDK. Thiếu/sai key hoặc API lỗi
thì action bị từ chối; không dùng lại pass để tiếp tục action đó.

`Observer/CheckAction::EVENTS` là const map đăng ký các event cần kiểm tra.
Observer frontend lọc `controller_action_predispatch` sau validation/form key native,
trước execute; gồm checkout Magento/One Step Checkout, login form/AJAX, register,
popup create và OAuth login/callback/email của Mageplaza Social Login đang có,
add-to-cart, submit order và contact form. POST tùy biến khớp PageRules cũng phải
có fresh verdict trước controller; các form ngoài rule cần khai báo đường dẫn POST.
Plugin `AuthAccess` bảo vệ `AccountManagementInterface::authenticate`, `createAccount`,
`createAccountWithPasswordHash` và `CustomerTokenServiceInterface::createCustomerAccessToken`.
Các service native này được dùng bởi storefront, REST và GraphQL; client headless
phải giữ browser session đã xác minh, không truyền visitor ID tùy ý.

Plugin trước `Quote::addProduct` và `QuoteManagement::submit` bảo vệ tầng nghiệp vụ,
kể cả REST/GraphQL sử dụng quote đã được storefront xác minh và đúng owner.
Kết quả chỉ được dùng lại trong một service invocation cùng action/store/browser/actor/quote;
trước hook lồng nhau vẫn inspect grant hiện tại. Invocation kế tiếp, action khác
hoặc quote khác phải kiểm tra private API mới, kể cả trong cùng HTTP batch.
Observer predispatch độc lập vẫn có thể tạo thêm check trước service invocation.
State được xóa trong finally và qua ResetAfterRequestInterface.

Payment được bảo vệ tại lúc submit quote/place order, trước khi native order/payment
bắt đầu. Block, outage, schema sai hoặc visitor mismatch không cho callback native chạy.
Không hook vào payment import, authorize/capture/invoice/pay/webhook sau đó, theo
phạm vi đã thống nhất. Admin và cron giữ ngoại lệ hiện tại. Native placeOrder dùng
CartMutex của Magento để ngăn thao tác song song trên cùng quote; module không tự
thay thế mutex hoặc tuyên bố mọi gateway có tính idempotent.

Visitor ID lấy từ session/quote binding do Guard lưu ở server; không đọc visitor
ID tùy ý từ POST/cookie client. Chưa có visitor thì chuyển sang gate để xác minh
SDK. Visitor còn block được trả ngay trạng thái block, không gọi API/SDK nữa.

`POST {HideGuard URL}/api/v1/verdict` dùng API key của environment/store scope,
TLS verification, không follow redirect/retry, timeout kết nối 3 giây/tổng 8 giây.
Response unsigned theo contract `visitorId`, `verdict`, `score`, `reasons`,
`source` được validate bằng `ApiVerdictVerifier` riêng. Verifier này chỉ được
inject vào Guard của backend action; controller nhận response SDK vẫn dùng
Ed25519Verifier và từ chối payload unsigned. Backend kiểm tra visitor response
trùng visitor đã gửi, rồi Guard chung áp dụng constants verdict, grant và block
vào các bảng hiện tại. Không có bảng hay cơ chế block thứ hai.

Allow cho phép chạy action và bind lại quote, không kéo dài pass hiện có.
Block dừng action và dùng gate `/antidetectbrowser/gate/index`; POST form thường
redirect 303, AJAX/API nhận JSON 401/Retry-After. Lỗi hoặc response không hợp lệ
thu hồi grant và dùng gate lỗi, không tự tạo ban có thời hạn. Plugin response
bảo toàn denial kể cả khi controller đã catch exception. AMD frontend nghe
`ajaxPrefilter`/`always` của jQuery/mage/storage (kể cả `global:false`) qua header riêng, khóa tương tác và chuyển
sang gate chung; không chuyển hướng khi gặp 401 từ hệ thống khác. Adapter cũng bọc
fetch cùng origin để xử lý header denial, dùng response.clone() để giữ body cho caller.
Khi history pushState/replaceState/popstate đổi path hoặc query, adapter khóa body
và reload để Magento tính lại policy. Hash-only không reload; đây là fallback
navigation đầy đủ, không phải tích hợp router SPA riêng. Headless client không tải
adapter cần xử lý JSON verificationUrl/header denial trong UI của mình.

Client/transport dùng generated factory và virtual type native để giữ đúng cấu
hình khi compile DI cho thư viện Composer. Cache theo request được reset qua
`ResetAfterRequestInterface` của Magento 2.4.7+. Khi đổi DI ở project đã cài,
clean cache config và metadata sinh ra trước khi compile lại từ source mới.

Để debug, bật **Protection → Debug Log → Yes** rồi lưu cấu hình. Mỗi request
backend thực sự gửi tới HideGuard có hai bản ghi request/response hoặc request/error,
ghép bằng `request_id`, gồm store ID, method, URL, HTTP status và `duration_ms`.
Response chỉ ghi verdict, score, tối đa 20 reason code và hash SHA-256 visitor ID
để đối chiếu; không ghi headers/API key, raw visitor ID, chữ ký, reason detail hay
raw body lỗi. JSON lỗi/quá lớn được ghi trạng thái thay vì nội dung. HTTP response
chưa qua Guard validation vẫn được ghi để chẩn đoán; verdict trong log không tự
đồng nghĩa với quyết định cho phép/chặn cuối cùng của Guard.

Tắt Debug Log dừng ghi thêm, không xóa file cũ. Lỗi ghi log không thay đổi verdict,
không retry request. Block còn hiệu lực hoặc chưa có trusted visitor không gọi
API nên không có bản ghi request mới. Log này không thu thập request của SDK chạy
trực tiếp trong trình duyệt, dữ liệu form login, giỏ hàng hoặc payment.

## Chặn trang từ backend và frontend

1. Request tới trang được bảo vệ chạy qua `PageAccess` trước plugin cache.
   Server kiểm tra session, customer, block, thời hạn grant và fingerprint setting.
2. Chưa có quyền: GET điều hướng hiển thị nội dung trang để xem, dưới lớp phủ
   **trong suốt** khóa tương tác. Đây không phải cơ chế giấu nội dung HTML khi đang
   xác minh. Ajax, POST và HEAD vẫn bị trả HTTP 403, không thực thi action; backend
   tiếp tục yêu cầu grant cho thêm giỏ hàng và đặt đơn.
3. Đã xác nhận Block: GET **chuyển hướng HTTP 302 tới `/antidetectbrowser/gate/index`**;
   gate trả HTTP 401 với theme/layout native. Không dispatch action hoặc trả HTML
   của trang gốc. Block mới và lượt truy cập trong thời gian cấm dùng cùng gate. Ajax,
   POST, HEAD và endpoint check/verdict trả 401 với trạng thái Block. Header
   `Retry-After` báo thời gian còn lại; thời hạn block không bị kéo dài. Lỗi storage
   không cho chạy trang gốc và chuyển sang gate báo lỗi.
4. Backend đặt `inert` lên body qua Magento PageConfig trước khi render HTML để
   khóa tương tác ngay cả khi RequireJS chưa chạy. `access.js` khởi tạo bằng
   `text/x-magento-init`, gọi runtime AMD dùng chung để chuyển khóa sang
   các thành phần của trang, kể cả thành phần thêm sau; không chèn thẻ script
   trực tiếp cho file JS của module. Frontend POST preflight có form key và
   chỉ tải HideGuard SDK khi cần verdict mới. Các mốc Recheck Interval cho phép
   dùng lại pass chưa hết hạn mà không gọi SDK; chọn Luôn luôn vẫn kiểm tra mỗi
   lần tải trang được bảo vệ. Trang giữ khóa trong suốt cho đến khi backend xác
   nhận pass còn hạn hoặc verdict mới. User còn thời gian Block không gọi SDK;
   không tải SDK trước khi preflight xác định trạng thái truy cập.
   Lỗi mạng/SDK giữ khóa và chuyển tới gate xác minh thất bại với nút thử lại.
5. `fpc:verdict` được POST đầy đủ lên server để xác minh chữ ký và áp dụng nhóm verdict.
   Chỉ sau khi server xác nhận và gắn quyền vào quote thì trang được mở tương tác; nếu đang ở màn
   chặn riêng thì chuyển về URL nội bộ ban đầu.
   Gate giữ card trên nền trắng trong lúc xác minh lại và chờ trang đích tải sau
   Allow, không lộ tiêu đề/nền theme giữa hai trang. Lớp phủ pending trên trang
   được bảo vệ thông thường vẫn trong suốt.
   Request tiếp theo vẫn được kiểm tra lại từ backend.
6. Block được ghi vào database theo visitor/customer và gắn vào browser session.
   Đổi visitor trong session không bỏ được block. Frontend chuyển tới gate để dừng SDK; các tab khác nhận tín hiệu block cũng
   chuyển tới gate và kiểm tra server.
7. Grant hết hạn sau khoảng Recheck Interval đã chọn, mặc định 5 phút; frontend
   giữ nguyên deadline backend trả về và bắt đầu kiểm tra lại khi hết hạn.
   Luôn luôn không dùng lại pass trên lần tải trang tiếp theo; grant 5 phút vẫn
   cho phép thực hiện thao tác được bảo vệ trên trang hiện tại như trước.
   Preflight/Allow lặp lại không kéo dài cùng grant.
   Đổi customer, đổi setting, hết hạn hoặc verdict không hợp lệ đều cần xác minh
   lại. Đặt đơn thành công thu hồi grant của quote. SDK timeout/lỗi mạng không mở
   khóa trang; browser back/forward cache được reload để kiểm tra lại.

Các endpoint gate/check/verdict/fail luôn được miễn chặn để tránh vòng lặp. File
static/media được web server phục vụ trực tiếp; chúng không thuộc luồng trang
Magento. Admin, cron và tác vụ CLI không bị áp rule storefront.

Grant lưu phía server và gắn với quote ID, store, customer; không có access token
hay session hash được đưa vào cart attribute hoặc cho browser lựa chọn qua request.
Các luồng headless/checkout tùy biến phải dùng quote đã được storefront xác minh;
module không cung cấp endpoint phát quyền cho một cart ID do client gửi vào.

## Varnish / CDN

Một cache reverse proxy trả HTML trực tiếp sẽ không chạy PHP để kiểm tra session.
**Trước khi bật bảo vệ**, xuất VCL khi module đã được enable:

```bash
bin/magento varnish:vcl:generate --export-version=6 --output-file=var/hachitech-antidetect.vcl
```

Module sinh `return (pass)` theo host/base path của các store đang bật protection,
bao gồm URL secure/unsecure. Store chỉ dùng custom path/Complete order chỉ bypass
các đường dẫn đã chọn và endpoint gate. All pages hoặc các điểm Product/Cart/Checkout
bypass HTML toàn store, vì URL Rewrite có thể đổi mà không sinh lại VCL.
Encoded/noncanonical path và query đổi store được bypass bảo thủ; static/media và
PURGE vẫn dùng xử lý native. Store tắt protection giữ cache native.

Sau khi bật/tắt module, đổi phạm vi, base URL hoặc store, phải sinh lại, nạp VCL
và purge HTML cũ trước khi phục vụ policy mới. CDN phải áp dụng bypass tương ứng.
Module không tự nạp VCL vào proxy/CDN. Regex được xuất theo cú pháp VCL, không dùng
JSON escaping; xem [VCL Syntax](https://www.varnish.org/docs/users-guide/vcl-syntax/).

Module đặt `Cache-Control: private, no-store` cho gate/endpoint và HTML được bảo vệ.
Style của lớp khóa dùng `_module.less` import LESS dùng chung được publish từ
package; Magento gộp vào CSS của theme khi deploy static content.
HideGuard URL nằm trong group key của environment đang chọn và hỗ trợ kế thừa
Default/Website/Store View. CSP collector native thêm origin đang dùng vào
`script-src` và `connect-src`, thay cho whitelist cố định. Đổi URL sẽ yêu cầu
kiểm tra lại các pass cũ; block đang còn hạn vẫn giữ nguyên.
Built-in full-page cache cũng được kiểm tra quyền trước khi đọc cache. Module không
tự nạp VCL vào proxy hoặc thay đổi cấu hình CDN đang chạy.

## Xác minh và giới hạn

- Mọi environment dùng `Ed25519Verifier` của package để xác minh response
  HideGuard collect v2 bằng `ext-sodium`. Phải điền Ed25519 PEM public key cấp
  riêng cho site trong group key của environment đang chọn; không nhập private key.
- Backend kiểm tra canonical signature, environment và timestamp. Quyền pass
  bị giới hạn bởi timestamp đã ký và interval; response hết hạn không mở lại
  quyền pass hoặc block cũ. Các pass trước rollout phải check lại; block giữ deadline.
- Các const `BLOCK_VERDICTS`, `CHALLENGE_VERDICTS`, `ALLOW_VERDICTS` quyết định
  nhóm verdict; thay đổi này không sửa các nhóm bạn đang cấu hình.
  Việc xác minh chữ ký dùng cùng contract với Shopify. Request ID được ký nhưng
  không có nonce gắn với session
  hoặc cơ chế single-use; chưa thể tuyên bố chống replay giữa browser tuyệt đối.
- Browser session không phải IP ban. Xóa session/browser data có thể mất liên kết
  block ẩn danh; block customer vẫn áp dụng cho tài khoản đó.
- Trang đã được trả về trước một Block report không thể bị thu hồi từ bộ nhớ
  của browser. Kiểm tra backend áp dụng với mỗi request tiếp theo, và frontend
  khóa giao diện khi nhận Block/hết hạn grant.

Giữ rate limit 600 request/phút/store và 60/phút/session hoặc customer,
10.000 browser session/store, timeout HTTP 8 giây và SDK 35 giây.
Core Guard quyết định quyền; StateRepository chỉ map persistence, StoreLock
serialize mutation bằng Magento Lock Manager và transaction DB. External/nested
transaction bị từ chối để không commit transaction của nghiệp vụ khác.
Cron dọn session không hoạt động 30 ngày, block/rate window hết hạn mỗi giờ;
quyết định expiry không phụ thuộc cron.

## Các phần Shopify đã bỏ

Không có App Proxy, Shopify Admin API, OAuth/token Shopify, metafield, Validation
Function/Rust, đăng ký/reactivate rule Shopify, đồng bộ snapshot/lease, webhook
Shopify, theme editor activation hoặc giới hạn snapshot 9 KB / 250 grant.
Magento database, session, ACL, form key, quote và event đặt đơn thay thế các phần đó.

## Kiểm thử

```bash
docker exec troyclancy-php php vendor/bin/phpunit -c dev/tests/unit/phpunit.xml.dist app/code/Hachitechs/Hideguard/Test/Unit
node --test app/code/Hachitechs/Hideguard/Test/Frontend/access.test.mjs
docker exec troyclancy-php php app/code/Hachitechs/Hideguard/Test/Integration/local.php
```

Integration harness chỉ chạy với host local allowlist; enable module/cấu hình
trong tiến trình và dùng bảng MySQL temporary. Không enable module thật, chạy
migration, tạo order hoặc thay đổi dữ liệu cửa hàng. Kiểm tra DI, XML theo XSD,
thứ tự plugin cache, HTML gate, block/grant/reset/expiry/quote/customer/rate limit.
Frontend test dùng DOM/network giả lập; SDK thật với site key và proxy Varnish/CDN
đang chạy cần kiểm thử sau khi cài đặt.

## Package extraction và nâng cấp

`Model/Access` map store/customer/browser/quote sang typed core API. Policy,
AccessService, AccessException và interaction-lock cũ đã được xóa; shared tests
đã chuyển vào package. Lớp HttpStatus trung gian cũng đã bỏ; controller native
map lỗi core sang HTTP ngay tại luồng dùng chung của Check/Verdict. Config/HTTP/CSRF/ACL/hooks và UI native vẫn nằm ở module. Dropdown Environment,
Block Duration và Recheck Interval lấy danh sách giá trị hợp lệ từ core, chỉ
giữ nhãn dịch của Magento. PageRules quyết định phạm vi trang; JavaScript
không tự tính lại quyết định này.
Xem [tiến độ](https://github.com/namtb1994/hachitechs-hideguard-php/blob/master/docs/PROGRESS.md) và
[hướng dẫn runtime/build](https://github.com/namtb1994/hachitechs-hideguard-php/blob/master/docs/BROWSER.md).

Schema giữ nguyên ba bảng và thêm cột `verification` vào session. Chạy
`setup:upgrade` trước khi phục vụ source mới. Không xóa block/session khi nâng cấp;
hash visitor/customer và deadline gốc được giữ. Pass dùng fingerprint cũ cần
recheck. Namespace rate bucket thay đổi nên counter cũ hết hạn tự nhiên trong
60 giây; cutover bắt đầu window mới, cần tính đến cửa sổ này khi triển khai.
Adapter giữ format block và quote/customer fields; grant do core ghi có
fingerprint mới. Chưa diễn tập rollback source đầy đủ với state/traffic mới;
cần xác minh trên bản sao dữ liệu trước khi chọn đường rollback production.

Mỗi lần đổi runtime: build package, chạy script publish Magento trong BROWSER.md,
rồi compile DI/deploy static content và clean cache. Test publication `--check`
đảm bảo module không phục vụ runtime khác source của package.

Các bản AMD/LESS prebuilt được publish từ package cần giữ trong `view` để
Magento phục vụ asset; không chứa logic được viết riêng lần hai. Module trong
app/code không được đăng ký hay đóng gói như dependency Composer.
Trang ngoài rule vẫn có initializer để nhận tín hiệu từ chối action; không tự
chạy preflight/SDK khi mới tải trang. Các thao tác được kiểm tra trước khi thực thi.
PageRules bỏ truy vấn URL Rewrite khi chỉ dùng custom path hoặc Complete order.

[Báo cáo rà soát framework và chất lượng code](AUDIT.md) ghi lại các vấn đề đã sửa,
pattern đối chiếu, kết quả kiểm tra và giới hạn nghiệm thu.

## Xác minh chữ ký (đối chiếu lại ngày 19/10/2026)

Runtime gửi snapshot JSON đầy đủ qua field `response`; controller decode rồi
chuyển cho core, chỉ bind quote sau khi backend xác nhận Allow. Payload giả mạo
trả HTTP 400 và thu hồi grant/binding; active Block vẫn trả HTTP 401, không verify
hoặc gọi SDK. Key được validate khi admin bật protection và giữ ở backend.

Nhập key tại **Stores → Configuration → Hachitechs → HideGuard →
Protection → Environment → [environment] Keys → Signature Public Key**.
Mỗi environment dùng key riêng; admin kiểm tra định dạng bằng
`Ed25519Verifier::validatePublicKey()` của package. Runtime truyền key của
store scope đang dùng vào policy, rồi core gọi `Ed25519Verifier::verify()`
qua DI trước khi cấp quyền truy cập.

Module local đã có `registration.php` và được bật trong `app/etc/config.php`.
Native Magento DI đã resolve đúng verifier của package, static runtime đang
phục vụ khớp bản publish từ package. Integration dùng native Developer DI,
namespace cache/generated code riêng và bảng MySQL tạm. Không có schema mới.

## Luồng xác minh có hạn chờ ở backend

- GET thuộc rule bảo vệ gọi core `preflight` trước khi dispatch action/cache.
  Backend tạo mã xác minh và hạn chờ 35 giây; reload/mở tab không gia hạn lượt đang chờ.
- Trong hạn chờ, trang render dưới lớp phủ trong suốt và `inert`; các request xử lý
  nghiệp vụ trong phạm vi bảo vệ vẫn phải có quyền hợp lệ ở backend.
- Allow chỉ mở khóa sau khi backend xác thực chữ ký. Pass có thời hạn được tái sử
  dụng đến hạn gốc; Always thu hồi grant/quote binding cũ ngay khi bắt đầu lượt mới.
- Block chuyển tới gate và trả 401/Retry-After; không tải SDK trong thời gian cấm.
- Lỗi SDK, timeout hoặc response không hợp lệ chuyển tới gate lỗi. Core lưu/derive
  `verification_failed`; GET tiếp theo không trả HTML gốc. Đây không phải ban có thời hạn.
- Gate lỗi trả 403, có nút Try again. Nút gửi POST có form key để bắt đầu lại sau
  lỗi; không tự lặp vô hạn. Kết quả từ mã xác minh cũ không ghi đè lượt mới.
- Endpoint `access/fail` giữ được trạng thái thất bại khi client báo lỗi. Nếu request
  báo lỗi bị chặn, hạn chờ backend vẫn có hiệu lực với request tiếp theo.

Schema bổ sung `verification_id`, `verification_until` vào bảng session hiện có.
Chạy `bin/magento setup:upgrade`, compile DI và deploy static assets sau khi cập nhật.
Quyền/pending state của phiên bản cũ phải xác minh lại do policy fingerprint mới.
Core vẫn độc lập framework; Magento chỉ ánh xạ session/store/quote, schema, HTTP và UI.

HTML đã hiển thị là dữ liệu công khai của lượt preview; không thể thu hồi khỏi
browser. Backend không thể ép browser đã tắt JavaScript chuyển hướng; quyền xử lý
request vẫn do server quyết định. Xóa cookie tạo phiên browser mới có thể mở preview mới.


### Conformance hardening (2026-10-26)

Use the matching updated PHP package: private checks now delegate to its
`PrivateVerdictChecker`, including the visitor stored by the server. Authenticated
`challenge` is allowed under current HideGuard policy. Storage revocation also
cancels verification IDs/deadlines, retaining block deadlines; no schema change
is needed. HideGuard validates the registered domain and SDK public key, plus the
secret API key for backend requests. Signed collect responses retain signature,
environment and freshness checks; no separate site-ID setting is required.
The SDK proof still has no signed session/resource binding; this update does not
claim full replay prevention. The package limits private response bodies to 64 KiB.

### Kiểm tra sau cập nhật phạm vi (2026-10-26)

Endpoint check/verdict chỉ load/bind quote nếu checkout session đã có quote ID;
page-only không tạo quote mới. Quote hết hiệu lực trả về từ Magento không được bind nếu chưa có ID persisted. Test xác nhận getQuote/bindQuote không chạy khi chưa
có cart. API verdict vẫn chạy lại trước mỗi business invocation và không tăng TTL.

Bộ vector ở Test/Contract dùng một fixture key, 41 ca chung cho PHP/Node:
Allow/Challenge/Block trên ba environment, timestamp boundary, tampering, key sai,
reason order/schema/size, Unicode và token limits. Chạy Node đối chiếu bằng:

```bash
node --import /path/to/tsx/loader.mjs app/code/Hachitechs/Hideguard/Test/Contract/check-node.mjs /path/to/node-package/src/verification.ts
```

Integration harness có ca revoke trong khi API đang chờ, consume trong cùng invocation,
transaction rollback và CartMutex với process thứ hai. Dùng bảng temporary, fixture
HTTP và callback native giả lập; không tạo order hoặc gọi payment gateway thật.
Đo storage của page đã Allow so sánh preflight+bind cũ với preflight-only mới,
20 mẫu mỗi nhánh sau warmup; không phải latency SDK/API hay p50/p95 production.
Bằng chứng và giới hạn nghiệm thu được ghi trong AUDIT.md.

### AJAX nền trong lúc xác minh trang

Khi trang được bảo vệ đang chờ SDK/backend, request nền có thể nhận 403
verification_required. Adapter giữ nguyên lượt xác minh và lớp khóa thay vì
chuyển gate ngay; request đó vẫn bị backend từ chối và không tự phát lại.
Trạng thái pending được chụp lúc request bắt đầu để response đến muộn không
đẩy một trang vừa được Allow về gate. Block/lỗi thật và denial của request
bắt đầu sau Allow vẫn áp dụng luồng gate. All pages cũng miễn thao tác trên
chính gate, để Retry bắt đầu xác minh lại theo giao thức hiện có.
# Backend request queue during page verification (2026-10-27)

Protected pages install `js/request-gate.js` synchronously before Magento's native
RequireJS template. Component initialization and HTML rendering continue normally.
Same-origin application requests through asynchronous XMLHttpRequest (including
jQuery/mage/storage) or fetch wait for the shared runtime's backend-confirmed
`allowed` state, then send once. No widget names or widget endpoint lists are used.

GET/HEAD under the store's configured static/media roots, cross-origin traffic,
and the module's three verification endpoints bypass the queue. Static matching
includes unsigned and versioned URLs, including frontend HTML templates. The gate,
unprotected pages and disabled module do not install the barrier.

The native jQuery RequireJS mixin starts jQuery's timeout after release; native XHR
timeouts also start at actual send. Block, verification error, page exit, caller
abort or a 45-second fallback deadline cancel queued requests. Cancellation never
replays a mutation. A page restored from browser back/forward cache reloads to obtain
the current backend policy. Synchronous backend XHR cannot wait for asynchronous
verification and throws `InvalidAccessError` while pending; existing asynchronous
Magento flows are covered. Other transports such as JSONP script tags, iframes,
workers and WebSockets are outside this queue.

PHP wiring is in `Block/RequestGate.php`, `view/frontend/layout/default.xml`,
`templates/require-js.phtml` and `templates/request-gate.phtml`; jQuery timeout
integration is in `requirejs-config.js` and `js/jquery-request-gate.js`.
The generated shared `js/runtime.js` and server authorization policy are unchanged.

See [frontend test instructions](Test/Frontend/README.md). Native rendering checks:
`php app/code/Hachitechs/Hideguard/Test/Integration/local.php --request-gate-only`.
