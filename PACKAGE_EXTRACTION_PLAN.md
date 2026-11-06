# Kế hoạch tách package Antidetect dùng chung cho PHP

Ngày lập: 08/10/2026. Đây là kế hoạch lịch sử; trạng thái module hiện tại xem
[README.md](README.md). PHP core được phát hành từ repo riêng; tiến độ ghi tại
[PROGRESS.md](https://github.com/namtb1994/hachitechs-hideguard-php/blob/master/docs/PROGRESS.md).
Module Magento đã chuyển sang core; kết quả nghiệm thu adapter/runtime được cập nhật
trong PROGRESS.md. Plugin WordPress và ví dụ Laravel đã có bản development;
signed verification, cache/live SDK, full rollback và public release còn lại.

## 1. Mục tiêu và yêu cầu bắt buộc

Tách nghiệp vụ kiểm soát truy cập từ `Hachitechs_Hideguard` thành thư viện
Composer dùng được trong **mọi ứng dụng chạy PHP 8.1 trở lên**, gồm PHP thuần,
Magento, WordPress, Laravel, Symfony và các framework khác.

Tên đề xuất: `hachitech/antidetect-php`. Namespace đề xuất:
`Hachitechs\HideguardPhp`. Đây là tên dự kiến; chưa phải package đã phát hành.

Các yêu cầu nghiệm thu về tính độc lập:

- Core không require hoặc import Magento, WordPress, Laravel, Symfony hay
  container, ORM, router, request/response của bất kỳ framework nào.
- Core không đọc `$_GET`, `$_POST`, `$_COOKIE`, `$_SESSION`, không tự gọi
  `session_start()`, `header()`, `exit()` hoặc hàm/global của nền tảng.
- Ứng dụng truyền cấu hình, danh tính và ngữ cảnh vào API bằng object có kiểu
  dữ liệu rõ ràng; package trả quyết định hoặc lỗi nghiệp vụ.
- Không cần boot framework, đăng ký service provider, chạy command của
  framework hay cài adapter để sử dụng API core trong PHP thuần.
- Cài đặt bằng Composer không tự tạo bảng, sửa cấu hình ứng dụng, publish
  asset hoặc gửi request tới HideGuard.
- Cung cấp storage bền vững dùng được trong PHP thuần, cùng ví dụ tích hợp
  hoàn chỉnh. Storage trong bộ nhớ chỉ dùng cho test.
- Thư viện chuẩn và các PSR interface được phép dùng; chúng không phải
  framework. Mọi dependency bắt buộc phải tương thích PHP 8.1 và được kiểm
  tra khả năng cài cùng các nền tảng mục tiêu.
- SDK phát hiện browser vẫn chạy trên trình duyệt. API PHP quản lý quyền và
  xác minh verdict; không hứa phát hiện browser chỉ bằng PHP server.

Module/plugin của nền tảng tập trung vào giao diện, các field admin và lớp kết
nối với core. Lớp kết nối vẫn cần xử lý session, storage, điểm chặn request,
CSRF và cache để bảo vệ được cả frontend lẫn backend.

Phạm vi triển khai bao gồm **chuyển module `Hachitechs_Hideguard` sang
package mới và loại bỏ code thừa sau khi thay thế**. Không hoàn tất việc tách
khi module vẫn chạy engine cũ hoặc giữ một bản sao nghiệp vụ của package.

## 2. Tham khảo thiết kế của openai-php

Tham khảo mã nguồn trực tiếp, đối chiếu ngày 08/10/2026:

| Điểm tham khảo | Cách áp dụng cho Antidetect |
| --- | --- |
| [Client Composer manifest](https://github.com/openai-php/client/blob/main/composer.json): thư viện có PSR-4 và contracts HTTP chuẩn | Core là Composer `library`, autoload PSR-4; dependency được chọn theo nhu cầu thực tế |
| [Factory](https://github.com/openai-php/client/blob/main/src/Factory.php): cho phép truyền dependency trước khi tạo client | Cho phép truyền storage, verifier, clock và logger; có constructor trực tiếp và factory tiện dụng |
| [ClientContract](https://github.com/openai-php/client/blob/main/src/Contracts/ClientContract.php): public API có interface và kiểu trả về | `GuardContract`, context, policy, decision và lỗi nghiệp vụ có contract rõ ràng |
| [Response object](https://github.com/openai-php/client/blob/main/src/Responses/Chat/CreateResponse.php): kết quả có kiểu và chuyển được sang array | `AccessDecision` dùng trong PHP; serializer riêng xuất dữ liệu cần thiết cho browser |
| [Laravel Composer manifest](https://github.com/openai-php/laravel/blob/main/composer.json): gói Laravel require client riêng | Adapter phụ thuộc core; core không phụ thuộc ngược vào adapter |

`openai-php/client` trên nhánh `main` hiện yêu cầu PHP 8.2. Package Antidetect
giữ **PHP 8.1+ theo yêu cầu đã chốt**, chọn syntax và tooling phù hợp thay vì
sao chép toàn bộ dependency hoặc cấu trúc của package tham khảo.

HideGuard hiện được gọi qua browser SDK. Chỉ thêm transport PSR-18/PSR-17 nếu
có API server chính thức cần sử dụng; không bắt buộc Guzzle, HTTP discovery
hay một HTTP client cụ thể khi core chưa có nhu cầu đó.

## 3. Kiến trúc và chiều phụ thuộc

```text
Ứng dụng PHP thuần ───────────────────────┐
Module Magento ──────────────────────────┤
Plugin WordPress ────────────────────────┼──> hachitech/antidetect-php
Ứng dụng Laravel / adapter tùy chọn ──────┤      ├── PHP core
Ứng dụng PHP khác ───────────────────────┘      ├── storage/verification contracts
                                               └── browser runtime dùng chung
```

Core là repository/package riêng. Adapter Magento tiếp tục là module riêng;
plugin WordPress và adapter Laravel có vòng đời phát hành riêng. Không đưa
code adapter vào `src/` của core, kể cả dưới nhánh điều kiện kiểm tra framework.

Browser runtime nằm trong package để dùng chung thuật toán và protocol.
Ứng dụng PHP không dùng browser vẫn gọi được core mà không tải JavaScript,
jQuery, RequireJS hoặc Node.js. Node chỉ phục vụ build/test asset khi phát triển;
artifact đã build phải có sẵn trong bản phát hành.

## 4. Phân tách từ module hiện tại

| Thành phần hiện tại | Chuyển vào package | Giữ tại adapter Magento |
| --- | --- | --- |
| `Model/Policy.php` | Validation, thời hạn pass/block, fingerprint, quy tắc quyết định truy cập | Danh mục điểm truy cập đặc thù Magento và mapping cấu hình |
| `Model/AccessService.php` | Inspect, preflight, report, cấp/thu hồi quyền, bind/assert resource, reset block, rate limit | Đọc store/customer/session, lấy quote tin cậy và gọi API core |
| `Model/Config.php` và các source model | Object cấu hình, mặc định, danh sách environment/thời gian dùng chung | ScopeConfig, các field, scope inheritance và nhãn dịch trong admin |
| `Model/StateRepository.php` | Contract lưu state, block, grant, resource binding và rate window | ResourceConnection, tên bảng hiện tại và declarative schema |
| `Model/StoreLock.php` | Contract thao tác nguyên tử và quy tắc commit/rollback | Magento Lock Manager và transaction của connection Magento |
| `Model/BrowserIdentity.php` | Validation danh tính, quy tắc gắn quyền với browser/actor | Tạo/lưu bí mật browser bằng session của Magento, đọc customer |
| `Model/PageRules.php` | Rule đường dẫn tổng quát, so khớp điểm truy cập do ứng dụng cung cấp | URL Rewrite, full action name, nhận diện product/cart/checkout |
| `view/frontend/web/js/access.js`, `interaction-lock.js` | SDK lifecycle, khóa tương tác, deadline, queue, revision và đồng bộ tab | Bootstrap Magento, request có form key, dịch và nhận diện action của storefront |
| Controller, plugin, observer, block, template | Chỉ chuyển nghiệp vụ dùng chung đang nằm trong các lớp này | Router, HTTP response, ACL, CSRF, PageConfig, native initialization và render |
| Quote/order enforcement, cache/Varnish | API kiểm tra resource và dữ liệu quyết định | Hook quote/order, REST/GraphQL/SOAP, FPC, VCL và cache invalidation |
| Cron, reset admin, style | API cleanup/reset và browser state/style source dùng chung | Lịch cron, phân quyền reset và tích hợp `_module.less` của theme |

Không mang OAuth, App Proxy, metafield, Shopify Function hoặc rule riêng Shopify
vào core. Cũng không biến quote/store/customer của Magento thành điều kiện bắt
buộc với ứng dụng PHP khác.

## 5. Mô hình dữ liệu và public API

### 5.1. Kiểu dữ liệu tổng quát

- `ProtectionPolicy`: enabled, environment, key của môi trường hiện tại,
  rule, thời hạn pass/block và giới hạn lưu trữ/request.
- `AccessContext`: `scopeId`, `browserId`, `actorId` nullable và điểm truy cập
  đang kiểm tra. ID hỗ trợ chuỗi; không giả định customer/store là số nguyên.
- `ResourceRef`: loại resource và ID do server xác định, ví dụ cart, order
  hoặc nghiệp vụ riêng. Core không biết `Magento\Quote` hay model ứng dụng.
- `AccessDecision`: trạng thái allowed/pending/blocked/disabled, deadline,
  lý do và thông tin cần kiểm tra SDK. Lỗi cấu hình, storage, verifier hoặc
  rate limit là lỗi có kiểu riêng, không tự chuyển thành Allow.
- `VerifiedVerdict`: verdict đã được verifier kiểm tra, visitor ID và dữ liệu
  freshness/replay theo contract chính thức của HideGuard.

Điểm truy cập là tên do ứng dụng khai báo. `all_pages`/path rules dùng được
trong web PHP nói chung; product/cart/checkout/complete_order là mapping ở
adapter thương mại điện tử. Không ép website thông thường có các khái niệm đó.

### 5.2. API dự kiến

API PHP core dưới đây đã được triển khai trong mốc đầu; contract chi tiết và
giới hạn hiện tại nằm trong
[CONTRACT.md](https://github.com/namtb1994/hachitechs-hideguard-php/blob/master/docs/CONTRACT.md).
Magento, WordPress, Laravel và browser runtime đã được nối vào API này;
phạm vi kiểm thử và giới hạn release được ghi riêng trong PROGRESS.md:

```php
use Hachitechs\HideguardPhp\Guard;

require __DIR__ . '/vendor/autoload.php';

$guard = Guard::factory()
    ->withStorage($storage)
    ->withVerifier($verifier)
    ->withClock($clock)
    ->withLogger($logger)
    ->make();

$decision = $guard->inspect($context, $policy);
$decision = $guard->preflight($context, $policy);
$decision = $guard->report($context, $policy, $payload);

$guard->bindGrant($context, $policy, $resource);
$guard->assertResourceAllowed($context->scopeId, $policy, $resource);
```

- `inspect`: đọc trạng thái trước render/cache; không tạo grant, không gọi
  HideGuard hoặc tự phát HTTP response.
- `preflight`: xác định có cần verdict mới, áp rate limit và mutation cần thiết.
- `report`: xác minh verdict rồi cập nhật state trong thao tác nguyên tử.
- `bindGrant`/`assertResourceAllowed`: gắn/kiểm tra quyền cho resource tin cậy
  từ server; không cấp quyền cho cart ID tùy ý do browser gửi.
- Bổ sung revoke, consume grant, reset block và cleanup với scope rõ ràng.

Chốt chữ ký và semantics trong giai đoạn 1. Public API không nhận request,
response hoặc entity của framework. Factory báo lỗi nếu thiếu storage/atomic
mutation; không tự dùng memory storage trong môi trường thật. Verifier mặc định
luôn từ chối report, để vẫn kiểm tra được block đã lưu khi verifier chưa sẵn sàng;
DevelopmentVerifier phải được inject tường minh.

Serializer cho browser chỉ xuất public site key và dữ liệu cần cho giao diện;
không xuất session secret/hash, danh tính nội bộ hoặc key xác minh chỉ dùng
phía server. Adapter chịu trách nhiệm escaping, JSON và bản dịch.

## 6. Contracts, storage và concurrency

Các điểm mở rộng cần thiết: `GuardContract`, storage, atomic mutation và
`VerdictVerifier`. Dùng clock chuẩn PSR-20 và logger PSR-3, với implementation
mặc định không phụ thuộc framework. Chọn constraint phiên bản sau khi kiểm
tra Composer trên PHP 8.1 và Magento hiện tại.

Storage phải bảo đảm:

- State tách theo scope/browser/actor; grant gắn policy và resource chính xác.
- Report, block, grant, revoke và rate counter có transaction/lock hoặc cơ chế
  nguyên tử tương đương. Contract nêu rõ phạm vi lock và thứ tự cập nhật.
- Block đang có ưu tiên hơn Allow đến muộn; concurrent requests không vượt
  rate limit hay làm mất block. Report Block lặp không kéo dài deadline cũ.
- Việc thu hồi quyền do verdict không hợp lệ phải được lưu ngay cả khi API
  trả lỗi nghiệp vụ; lỗi hạ tầng thực sự rollback phần ghi chưa hoàn thành.
- Hạn quyền được kiểm tra theo clock khi request tới; cleanup không quyết
  định việc quyền đã hết hạn hay chưa.

Cung cấp một driver PDO bền vững cho PHP thuần. Giai đoạn đầu chọn một engine
SQL được kiểm thử đầy đủ, ưu tiên MySQL theo hệ thống hiện tại; ghi rõ engine
và extension cần thiết. Không tuyên bố driver hỗ trợ mọi database chỉ vì dùng
PDO. Driver chỉ cần extension khi được sử dụng, không ép adapter khác cài nó.

Schema/migration và bước khởi tạo do ứng dụng chủ động chạy. Có ví dụ tạo bảng,
transaction và cleanup cho PHP thuần; giữ driver Magento dùng bảng hiện tại.
Redis hoặc driver khác chỉ thêm khi có nhu cầu và chạy cùng bộ contract test.

Core nhận browser identity do server tạo và lưu. Adapter chịu trách nhiệm
cookie/session an toàn, tính ổn định qua session regeneration và thay đổi
đăng nhập; không tin browser ID hay actor ID do client tự khai báo. Ví dụ PHP
thuần phải cung cấp cách tích hợp này, không chỉ khởi tạo object Guard.

## 7. Giữ nguyên hành vi bảo vệ

Các hành vi sau là baseline khi chuyển module sang core:

| Trường hợp | Hành vi phải giữ |
| --- | --- |
| Trang/action không thuộc rule | Không preflight hoặc tự tải HideGuard SDK; chỉ chọn Product thì homepage không gọi SDK |
| Chưa xác minh | Trang GET có nội dung bên dưới lớp khóa trong suốt; khóa tương tác từ HTML ban đầu; action được bảo vệ chưa có grant bị từ chối |
| Pass còn hạn, interval có thời gian | Dùng lại quyền; không gọi SDK trước deadline backend xác định |
| Interval Always | Kiểm tra SDK mỗi lần tải trang được bảo vệ; grant 300 giây phục vụ action của trang hiện tại như module đang có |
| Pass hết hạn | Khóa tương tác và kiểm tra lại; preflight/Allow lặp không tự gia hạn cùng grant |
| Block còn hạn | Adapter trả HTTP 401 trước action/cache HTML; không gọi SDK, không trả trang gốc; deadline không bị kéo dài |
| Block hết hạn | Request được bảo vệ trở lại luồng kiểm tra; browser tự xử lý mốc hết hạn qua protocol chung |
| SDK/mạng/verifier/storage lỗi | Không mở quyền; màn trắng Block chỉ xuất hiện khi backend xác nhận Block |
| Thay đổi actor/policy hoặc verdict không hợp lệ | Thu hồi hoặc không sử dụng lại quyền sai ngữ cảnh |
| Hoàn tất nghiệp vụ cần consume | Thu hồi grant của resource sau thành công, theo hook của adapter |

Pass interval: `0 / 60 / 300 / 900 / 3600` giây, lần lượt Always / 1 phút /
**5 phút mặc định** / 15 phút / 1 giờ. Block duration giữ các lựa chọn
`900 / 3600 / 21600 / 86400` giây.

Giữ baseline rate limit 600 request/phút/scope, 60/phút/browser hoặc actor,
10.000 browser session/scope; session không hoạt động 30 ngày được cleanup.
Các giá trị thành cấu hình có validation và mặc định rõ ràng.

Core trả quyết định nghiệp vụ. Adapter web thống nhất mapping: Block còn hạn
401 kèm `Retry-After`; pending action bị từ chối 403; response bảo vệ và
endpoint `private, no-store`. Endpoint check/verdict/gate cần exemption giới
hạn chính xác để tránh vòng lặp. Admin, CLI, cron được adapter phân loại theo
ngữ cảnh; core không mặc định mọi request là storefront Magento.

Khóa trong suốt không giấu HTML đã trả về. Nếu dự án cần giữ bí mật nội dung
trước khi có quyền, adapter phải chọn luồng gate trước render. PHP chỉ kiểm
tra request đi qua ứng dụng: reverse proxy/CDN trả cache trước PHP cần được
bypass hoặc tích hợp riêng để chặn từ backend ngay khi truy cập.

## 8. Browser runtime dùng chung

Tách thuật toán khỏi AMD, jQuery, Magento URL/form key và selector riêng.
Runtime nhận dependency/configuration từ adapter:

- Hàm gửi request preflight/report; CSRF token do adapter quản lý.
- Public site key của environment đang chọn; URL SDK theo contract HideGuard.
- Hàm dịch/render thông báo, nhận diện page/action cần bảo vệ và callback UI.
- Trạng thái server, deadline và protocol version để tránh lệch core/asset.

Giữ queue report, revision, chống Allow đến muộn ghi đè Block, đồng bộ tab,
reload khi nhận Block để dừng SDK và recheck khi quay lại từ back/forward cache.
Giữ timeout HTTP 8 giây và SDK 35 giây, cho phép cấu hình hợp lệ nếu cần.

Phát hành bản ESM cho ứng dụng thông thường và bản AMD/UMD tương thích Magento.
Asset dùng chung có source và build xác định; adapter publish/copy ở bước build
hoặc lệnh cài đặt tường minh. Không sửa `vendor/` hay copy mỗi request.

Magento tiếp tục khai báo qua `text/x-magento-init`/RequireJS và biên dịch style
qua **`_module.less`**. WordPress enqueue asset qua API native. PHP thuần có ví
dụ phục vụ asset và init runtime. Core không đưa thẻ script, selector checkout
hay implementation detail vào giao diện admin dùng chung.

## 9. Cấu trúc repository dự kiến

```text
antidetect-php/
├── src/
│   ├── Guard.php
│   ├── Factory.php
│   ├── Contracts/
│   ├── Configuration/
│   ├── Access/
│   ├── Rules/
│   ├── Verification/
│   ├── Storage/
│   └── Exceptions/
├── browser/
│   ├── src/
│   ├── dist/
│   ├── build.mjs
│   └── publish-magento.mjs
├── tests/
│   ├── Unit/
│   ├── Contract/
│   └── Integration/
├── examples/plain-php/
├── docs/
├── composer.json
├── README.md
└── CHANGELOG.md
```

Composer dùng `type: library`, PSR-4 `Hachitechs\HideguardPhp\` và constraint
PHP `^8.1` cho dòng PHP 8.x. PHP major tương lai chỉ thêm sau khi kiểm chứng;
không tuyên bố tương thích một phiên bản chưa được test. Không dùng readonly
class hoặc API chỉ có từ PHP 8.2 trong code hỗ trợ 8.1.

Test, static analysis và formatter thuộc `require-dev`, không kéo vào ứng
dụng khi cài `--no-dev`. Chọn tooling chạy được trên PHP 8.1; không sao chép
tooling của `openai-php` nếu nó nâng PHP tối thiểu lên 8.2.

## 10. Adapter theo nền tảng

### Magento — tích hợp đầu tiên

- Module require core qua Composer, map config sang `ProtectionPolicy` và
  truyền store/browser/customer/resource lấy từ server.
- Giữ field Protection, nhóm key ngay dưới Environment, chỉ hiện nhóm đang
  chọn; giữ dropdown interval mặc định 5 phút, ACL, scope và dịch hiện tại.
- Giữ plugin trước FPC, hook quote/order và các luồng REST/GraphQL/SOAP; gọi
  API core thay vì giữ bản sao nghiệp vụ trong module.
- Dùng storage adapter cho bảng hiện có; asset được đưa vào vị trí Magento
  nhận diện ở bước build và init theo pattern framework.
- Giữ VCL/cache integration, CSP, form key, logging và cron native.

#### 10.1. Kế hoạch thay thế và loại bỏ code thừa trong module

Các đường dẫn dưới đây tương đối với `app/code/Hachitechs/Hideguard`.
Chỉ xóa sau khi chức năng thay thế đã được nối vào module và kiểm thử đạt;
đây là bảng yêu cầu cutover. Các phần đã thay/xóa và kết quả kiểm thử thực tế
được ghi trong PROGRESS.md; không dùng bảng kế hoạch làm bằng chứng nghiệm thu.

| File/thành phần hiện tại | Xử lý sau khi dùng package |
| --- | --- |
| `Model/Policy.php` | Chuyển validation, fingerprint, pass/block semantics vào core rồi xóa class cũ; các consumer gọi policy/configuration API của package |
| `Model/AccessService.php` | Loại bỏ state machine, TTL, verdict và rate-limit logic cũ; thay bằng Guard của package và lớp bridge Magento nếu cần gom context/quote mapping; xóa class cũ khi không còn trách nhiệm riêng |
| `Model/AccessException.php` | Thay bằng lỗi nghiệp vụ của package và mapping HTTP/LocalizedException ở adapter rồi xóa class cũ; không đưa `statusCode` HTTP của class này vào core |
| `Model/StateRepository.php` | Chuyển thành storage implementation của contract core; giữ truy vấn ResourceConnection/bảng hiện có, bỏ quyết định cấp quyền, rate policy và luật retention đã chuyển vào core |
| `Model/StoreLock.php` | Chuyển thành atomic mutation implementation dùng Magento Lock Manager/transaction; bỏ coupling với AccessException cũ và áp contract commit/rollback mới; không giữ thêm một lớp lock cũ chạy song song |
| `Model/Config.php` | Giữ ScopeConfig/scope inheritance và mapping typed policy; bỏ validation nghiệp vụ, tính TTL/fingerprint hoặc danh sách core bị sao chép |
| `Model/BrowserIdentity.php` | Giữ tạo/lưu browser secret trong session Magento và đọc customer; bỏ phần validation tổng quát đã có trong core, map guest thành actor nullable |
| `Model/PageRules.php` | Giữ nhận diện route Magento, SEO rewrite và exemption nội bộ; dùng matcher core cho custom path/rule, xóa matcher tổng quát bị trùng |
| `Model/Config/Source/Environment.php`, `Duration.php`, `PassDuration.php` | Giữ source model OptionSourceInterface và nhãn dịch native; lấy giá trị được hỗ trợ từ package, bỏ danh sách enum/thời gian bị sao chép |
| `Model/Config/Source/Points.php` | Giữ danh mục product/cart/checkout/complete_order dành cho Magento và mapping điểm truy cập; core không cần require danh mục này |
| `view/frontend/web/js/access.js` | Thay implementation SDK/state machine bằng entry AMD mỏng gọi runtime package; chỉ giữ form key, translation, selector/action mapping và native initialization |
| `view/frontend/web/js/interaction-lock.js` | Chuyển implementation dùng chung vào runtime package; xóa source cũ hoặc thay bằng entry tương thích cần thiết, không giữ hai implementation khóa tương tác |
| `view/frontend/web/css/source/_module.less` | Giữ entry LESS native; import source style dùng chung đã publish vào module và chỉ giữ override Magento cần thiết, bỏ style bị nhân đôi |
| `Test/Unit/Model/PolicyTest.php` | Chuyển test nghiệp vụ vào package rồi xóa test class cũ; adapter chỉ test config mapping và tích hợp policy của package |
| `Test/Frontend/access.test.mjs` | Chuyển test runtime chung vào package; thay bằng test entry Magento/form key/selector/asset nếu cần, bỏ test chỉ phục vụ implementation JS đã xóa |

Tên class adapter mới chỉ chốt khi implement; không tạo thêm lớp chỉ để đổi tên.
Có thể giữ tên `StateRepository`/`StoreLock` nếu implementation còn đúng trách
nhiệm Magento và dễ hiểu. Facade tương thích chỉ tồn tại khi có consumer thực
tế cần nó; ghi rõ điểm bỏ facade, không để forwarding class vô hạn sau cutover.

#### 10.2. Các phần phải giữ và làm mỏng

- `Controller/Access/Check.php`, `Verdict.php`: giữ route, form key, giới hạn
  body, Json response và quote lấy từ checkout session; gọi preflight/report
  core. Bỏ validation verdict, cấp quyền hoặc TTL trong controller.
- `Controller/Gate/Index.php`, `Controller/Adminhtml/Blocks/Reset.php` và các
  block/template: giữ render, ACL, URL nội bộ an toàn, HTTP và admin UI; reset
  gọi API core trong scope server xác định.
- `Plugin/PageAccess.php`, `PrivatePages.php`, `QuoteAccess.php`,
  `OrderAccess.php`, `ValidateSettings.php`, `VarnishAccess.php`: giữ hook và
  thứ tự native, chuyển kiểm tra nghiệp vụ sang core. **Không xóa hook chặn
  backend, quote/order hoặc cache chỉ vì frontend đã gọi package.**
- `Observer/ConsumeGrant.php`, `SettingsChanged.php`: giữ event Magento,
  mapping quote/scope và cache clean; gọi API consume/revoke của core thay
  vì thao tác nghiệp vụ trực tiếp trên repository cũ.
- `etc/adminhtml/system.xml`, `config.xml`, ACL, routes, layout, CSP, schema,
  registration và module sequence: giữ những phần vẫn cần cho adapter.
  Default XML phải khớp core, gồm interval 300 giây, có test chống lệch.
- `view/adminhtml/web/js/reset-blocks.js` và template reset: giữ flow admin
  native, chỉ đổi contract endpoint nếu cần; không xóa cùng runtime frontend.

#### 10.3. Dọn wiring, dependency, asset và tài liệu

1. Thêm Composer require core với constraint đã kiểm chứng; cấu hình Magento
   DI để tạo Guard và bind storage/atomic/verifier/clock/logger. DI cấu hình
   nằm ở module, không đưa ObjectManager hoặc Magento factory vào package.
2. Đổi mọi constructor/import/PHPDoc/catch sang contract mới. Rà `etc/di.xml`,
   `etc/frontend/di.xml`, events/layout và cron để không tham chiếu class đã
   xóa. Cron hiện gọi `StateRepository::cleanup`; đổi sang entry cleanup mới
   hoặc adapter đã chuyển đổi, vẫn giữ lịch chạy native.
3. Sau khi chuyển caller, xóa engine cũ và helper/constant không còn consumer;
   không để nhánh fallback sang engine cũ khi package lỗi. Lỗi vẫn phải giữ
   hành vi từ chối cấp quyền.
4. Chỉ giữ một bản runtime được build/publish từ package. Rà AMD imports,
   layout và `bootstrap.phtml`; tránh double initialization và listener SDK
   trùng. Giữ `text/x-magento-init`, không đổi sang script tag tải local JS.
5. Loại dependency Composer/module sequence không còn được adapter sử dụng
   sau khi kiểm tra reference thực tế. Không xóa Magento_Quote, Customer,
   PageCache hoặc dependency khác còn phục vụ hook/native integration.
   Path repository dùng để phát triển phải được thay bằng nguồn phát hành
   trước release.
6. Giữ bảng/state/block hiện có. Chỉ thay/xóa column, index hoặc schema
   whitelist khi migration riêng chứng minh không mất dữ liệu đang cần;
   không coi bảng block là phần thừa chỉ vì core có storage mới.
7. Chuyển test chung sang package, giữ test adapter cho DI/config/route/
   quote/order/cache/CSRF/UI và cập nhật integration harness. Cập nhật README,
   hướng dẫn Composer/build/deploy, migration và changelog; giữ AUDIT như
   baseline lịch sử, không dùng nó để tuyên bố package mới đã pass.
8. Build lại DI/static content bằng quy trình Magento; dọn artifact sinh ra
   thuộc module đã thay thế và clean cache liên quan. Xác minh pub/static
   và theme CSS không tiếp tục phục vụ runtime/style cũ.

#### 10.4. Điều kiện hoàn tất việc dọn module

- Không còn consumer tham chiếu Policy, AccessException hoặc engine AccessService
  cũ đã xóa; không còn DI/event/cron/layout trỏ tới class/file không tồn tại.
- Chỉ core quyết định verdict, grant/block, TTL và rate policy; module chỉ
  map dữ liệu và thực thi quyết định qua hook của Magento.
- Chỉ một runtime/interaction-lock implementation hoạt động; admin reset,
  chặn page/backend và settings environment vẫn hoạt động.
- Dependency mới cài được cùng Magento 2.4.7-p10/PHP 8.2 của dự án; core vẫn
  chạy độc lập trên PHP 8.1. DI compile, XML validation, static deploy, unit,
  browser và integration regression đều đạt.
- Block/deadline đang có được giữ; việc xóa code thừa không xóa dữ liệu,
  không làm mất grant binding hoặc mở quyền khi package gặp lỗi.

### WordPress — kiểm chứng nền tảng thứ hai

- Plugin require core; Settings API/admin UI, capabilities, nonce/CSRF,
  endpoints, cookie/browser identity, `$wpdb` storage và asset enqueue là
  trách nhiệm plugin.
- Hỗ trợ Composer project; ZIP phân phối kèm dependency được build sẵn.
  Kiểm tra xung đột autoload/dependency khi nhiều plugin cùng bundle thư viện;
  chọn cơ chế đóng gói phù hợp trước phát hành.
- WooCommerce là integration tùy chọn. Chỉ thêm rule/cart/order hooks khi
  WooCommerce được dùng, không require nó trong core hoặc plugin cơ bản.
- Chặn phải chạy trước nguồn cache phục vụ HTML. `advanced-cache.php` của
  WordPress được tải trước plugin thông thường và MU plugin, nên chỉ thêm
  hook plugin/MU plugin chưa đủ: cần bypass hoặc kết nối cache drop-in.
  Bằng chứng: [wp-settings.php](https://github.com/WordPress/wordpress-develop/blob/trunk/src/wp-settings.php).

### Laravel và các ứng dụng PHP khác

- Dùng core trực tiếp qua constructor/factory, cấu hình và dependency của
  dự án; không yêu cầu package Laravel để gọi Guard.
- Nếu cần tiện ích, phát hành bridge riêng gồm service provider, config,
  middleware và storage binding. Bridge require core, không đảo chiều.
- Kiểm tra request trước response cache/render; kiểm tra nghiệp vụ ở service
  khi tạo/thay đổi resource. Không chỉ khóa DOM rồi coi backend đã được bảo vệ.
- Core PHP 8.1 không làm framework hỗ trợ PHP 8.1 nếu framework có yêu cầu cao
  hơn. Matrix phiên bản adapter được chốt từ manifest của nền tảng mục tiêu.

## 11. Các giai đoạn triển khai

Trạng thái ngày 08/10/2026: giai đoạn 1–3 đã có implementation và kiểm thử local;
giai đoạn 4 có plugin single-site/ví dụ Laravel/ZIP development, còn kiểm chứng
cache thật và dependency scoping cho ZIP public. Giai đoạn 5 chưa hoàn tất.
Các checklist dưới đây giữ vai trò yêu cầu triển khai/phát hành.

### Giai đoạn 1 — Chốt contract và baseline

1. Ghi nhận luồng và bộ test module hiện tại; phân loại nghiệp vụ core/adapter.
2. Chốt context, policy, decision, verifier, storage và atomic semantics.
3. Chốt protocol browser, mapping HTTP ở adapter và compatibility asset/core.
4. Chốt schema PDO đầu tiên, giới hạn dữ liệu và danh tính do server quản lý.

Đầu ra: API/contract document, bảng mapping và acceptance matrix. Hoàn thành
khi luồng PHP thuần có thể được mô tả mà không cần type/API của framework.

### Giai đoạn 2 — Xây PHP core và ví dụ PHP thuần

1. Tạo repository Composer, autoload, cấu hình và tooling PHP 8.1.
2. Tách policy/access/rate/grant/block/resource và verifier interface.
3. Tạo driver PDO đầu tiên, migration tường minh và bộ storage contract test.
4. Hoàn thiện ví dụ PHP thuần: browser identity, endpoint, rule, storage,
   bootstrap UI và chặn nghiệp vụ; phần browser nối vào giai đoạn 3.

Đầu ra: core chạy trong project Composer tối thiểu và CLI test harness, không
boot framework. Demo development ghi rõ giới hạn unsigned verdict.

### Giai đoạn 3 — Browser runtime và chuyển Magento

1. Tách runtime JavaScript, build ESM và AMD/UMD, giữ hành vi khóa hiện tại.
2. Tạo adapter config/session/storage của Magento, thin controllers/plugins.
3. Thay từng đường gọi nghiệp vụ bằng core; tránh chạy hai engine cấp quyền
   cùng lúc. Giữ native assets và `_module.less`.
4. Thực hiện checklist ở mục 10.1–10.4: xóa engine/helper/test trùng, làm mỏng
   adapter và dọn DI, dependency, cron, asset/tài liệu không còn cần.
5. Chạy regression PHP/JS/integration, DI compile, static deploy và cache check.

Đầu ra: Magento dùng core, không nhân đôi policy/state machine; ví dụ PHP
thuần chạy đủ luồng browser/backend. Luồng block/pass khớp baseline và module
đã loại bỏ implementation cũ không còn dùng, không chỉ thêm dependency mới.

### Giai đoạn 4 — WordPress và ví dụ Laravel

1. Tạo plugin WordPress cơ bản với admin, identity, storage và endpoints.
2. Kiểm tra cùng contract suite và kiểm thử page/cache thực tế.
3. Tạo ví dụ Laravel dùng trực tiếp core; bridge riêng chỉ khi có nhu cầu.
4. Chốt cách đóng gói ZIP/dependency và integration WooCommerce tùy chọn.

Đầu ra: chứng minh core chạy ở PHP thuần và ít nhất hai nền tảng độc lập;
những thay đổi cần để hỗ trợ WordPress không đưa WP dependency vào core.

### Giai đoạn 5 — Nghiệm thu và phát hành

1. Hoàn thiện đặc tả và implementation xác minh HideGuard cho signed environments.
2. Chạy compatibility/security/concurrency/cache suite và kiểm tra distribution.
3. Viết installation, adapter guide, custom storage/verifier guide, migration,
   upgrade/rollback, changelog và policy versioning.
4. Phát hành `0.x` để ổn định API; chỉ lên `1.0` khi contract và production
   verification đạt nghiệm thu. Không publish từ nhánh thử nghiệm tự động.

## 12. Chuyển dữ liệu và rollback Magento

- Ban đầu sử dụng bảng/session hiện tại qua adapter, hạn chế migration dữ liệu.
- Giữ block đang có và **deadline gốc**, kể cả khi đổi fingerprint policy.
  Không xóa block để đơn giản hóa migration.
- Nếu format grant/fingerprint thay đổi, có thể yêu cầu kiểm tra lại quyền
  pass; không diễn giải grant cũ thiếu dữ liệu thành Allow.
- Có schema/version mapping tường minh cho identity, resource binding và state.
  Chỉ dùng format mới sau khi các reader cần thiết hiểu nó.
- Kiểm tra rollback bằng state do phiên bản mới ghi, đặc biệt block mới. Nếu
  reader cũ không tương thích, cung cấp migration quay lại hoặc giới hạn rõ
  đường rollback; không hứa rollback chỉ bằng đổi Composer version.
- Giữ test migration cho block còn hạn, grant hết hạn, đổi customer/policy,
  quote đã bind và request đồng thời trong giai đoạn chuyển đổi.

## 13. Kiểm thử và tiêu chuẩn nghiệm thu

Baseline module đã ghi trong [AUDIT.md](AUDIT.md): 96 PHPUnit test / 191
assertion, 32 frontend test, integration Magento/MySQL, DI compile và static
deploy đã pass. Đây là kết quả trước khi tách; không thay thế kiểm thử package
hoặc adapter mới.

| Phạm vi | Điều kiện đạt |
| --- | --- |
| Framework independence | Project Composer trống cài core và chạy example/tests trên PHP 8.1; dependency tree không chứa framework; API không có type/global framework |
| Core behavior | Pass/block/expiry/reset/policy change/actor change/resource binding/consume/rate limit đúng baseline |
| Storage drivers | Cùng contract suite trên PDO và storage Magento/WordPress; test concurrency với storage thật, không chỉ mock |
| Race và lỗi | Block thắng Allow đến muộn; invalid verdict revoke được persist; rate increment nguyên tử; storage/SDK/network lỗi không mở quyền |
| Browser runtime | Product-only homepage không gọi SDK; active Block không gọi SDK; interval/default/deadline/transparent lock/white Block/BFcache/tab đúng |
| Backend enforcement | Protected actions bị chặn khi thiếu grant; active Block trả 401 trước action và trước cache HTML; endpoint exemption không rộng quá mức |
| Migration | Block/deadline không mất; quyền cũ không được nâng cấp sai; upgrade/rollback được kiểm chứng |
| Dọn module Magento | Engine cũ và code/test/asset trùng đã bỏ; wiring không dangling; chỉ còn core và adapter native, không có fallback cấp quyền bằng logic cũ |
| Platform patterns | Magento DI/XML/RequireJS/LESS, WordPress native APIs và bridge Laravel theo version được hỗ trợ |
| Distribution | Composer `--no-dev`, asset build sẵn, ZIP plugin, dependency compatibility và clean-install example chạy được |
| Code quality | Typed API, trách nhiệm rõ ràng, lỗi dễ truy vết, dependency tối thiểu; thay rule/storage không phải sửa controller từng framework |

CI core gồm PHP 8.1 và các phiên bản PHP 8.x được tuyên bố hỗ trợ; kiểm tra
Composer validate, lint, static analysis, unit/contract/integration và browser
test. CI adapter theo matrix framework/PHP riêng. Không ghi pass cho SDK thật,
proxy/CDN hoặc framework chưa chạy kiểm thử tích hợp.

Mỗi giai đoạn sửa code theo workflow analyzer → coder → tester → reviewer;
chỉ hoàn tất phần triển khai khi test PASS và review APPROVED.

## 14. Điều kiện còn thiếu và thứ tự ưu tiên

Người dùng xác nhận phía HideGuard sẽ ký response, nhưng implementation hiện
chưa hoàn tất. Giữ interface verifier để tích hợp contract chính thức khi có;
không coi phần ký đang chờ này là yêu cầu dừng việc tách core/adapter.

Module hiện từ chối verdict Staging/Production bằng HTTP 501 vì thiếu đặc tả
HideGuard về chữ ký, payload canonicalization, key, freshness và chống replay.
Xem [DevelopmentVerifier](https://github.com/namtb1994/hachitechs-hideguard-php/blob/master/src/Verification/DevelopmentVerifier.php)
và [giới hạn hiện tại](README.md).

Việc tách core/adapter có thể bắt đầu trước khi có đặc tả. Tuy nhiên, nghiệm
thu bảo vệ production phải có verifier theo contract chính thức và test dữ
liệu thật. Không tự suy đoán thuật toán, không chấp nhận unsigned verdict trong
production. Development verifier cần opt-in rõ ràng; Challenge đang được
xử lý như Allow chỉ được giữ như hành vi development, chưa phải proof xác minh.

Thứ tự ưu tiên: **contract + PHP thuần → core/storage → browser + Magento →
WordPress/Laravel → signed verification và phát hành**. Mỗi bước phải kiểm tra
core không nhận thêm phụ thuộc framework; phần tích hợp nền tảng nằm ở adapter.
