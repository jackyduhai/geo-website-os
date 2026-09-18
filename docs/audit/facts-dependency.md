app\Http\Controllers\Site\AboutController.php:21: $company = Facts::company();
app\Http\Controllers\Site\AboutController.php:22: $brand   = Facts::brandLanguage();
app\Http\Controllers\Site\AboutController.php:68: 'workshops' => Facts::workshops(),
app\Http\Controllers\Site\AboutController.php:69: 'regions'  => Facts::salesRegions(),
app\Http\Controllers\Site\ContactController.php:18: $company = Facts::company();
app\Http\Controllers\Site\ContactController.php:35: 'areaServed' => Facts::salesRegions(),
app\Http\Controllers\Site\CooperationController.php:19: $coop = Facts::cooperation();
app\Http\Controllers\Site\FactoryController.php:19: $company    = Facts::company();
app\Http\Controllers\Site\FactoryController.php:20: $workshops  = Facts::workshops();
app\Http\Controllers\Site\FactoryController.php:21: $regions    = Facts::salesRegions();
app\Http\Controllers\Site\FactoryController.php:22: $certsReady = Facts::certificationsReady();
app\Http\Controllers\Site\FactoryController.php:65: 'certs'      => Facts::certifications(),
app\Http\Controllers\Site\HomeController.php:29: $company = Facts::company();
app\Http\Controllers\Site\HomeController.php:44: $data['heroProduct'] = Facts::product('orleans-801');
app\Http\Controllers\Site\HomeController.php:52: $data['productLines'] = collect(Facts::productLines())->map(function ($l) {
app\Http\Controllers\Site\HomeController.php:53: $l['products'] = Facts::productsByLine($l['slug']);
app\Http\Controllers\Site\HomeController.php:148: $p = Facts::product($slug);
app\Http\Controllers\Site\HomeController.php:223: ['num' => count(Facts::workshops()), 'unit' => '大', 'label' => '自有生产车间'],
app\Http\Controllers\Site\HomeController.php:224: ['num' => count(Facts::salesRegions()), 'unit' => '大区', 'label' => '全国销售覆盖'],
app\Http\Controllers\Site\ProductController.php:21: $lines = collect(Facts::productLines())->map(function ($line) {
app\Http\Controllers\Site\ProductController.php:22: $line['products'] = Facts::productsByLine($line['slug']);
app\Http\Controllers\Site\ProductController.php:72: $data = Facts::line($line);
app\Http\Controllers\Site\ProductController.php:76: $products = Facts::productsByLine($line);
app\Http\Controllers\Site\ProductController.php:94: $url = Facts::isCoreProduct($p['slug'])
app\Http\Controllers\Site\ProductController.php:123: $product = Facts::product($slug);
app\Http\Controllers\Site\ProductController.php:124: abort_if(! $product || ! Facts::isCoreProduct($slug), 404);
app\Http\Controllers\Site\ProductController.php:128: $line     = Facts::line($product['line']);
app\Http\Controllers\Site\ProductController.php:129: $related  = Facts::relatedProducts($product, 5);
app\Http\Controllers\Site\ProductController.php:130: $scenes   = Facts::scenesOfProduct($product);
app\Http\Controllers\Site\ProductController.php:191: foreach (Facts::productLines() as $l) {
app\Http\Controllers\Site\SolutionController.php:18: $scenes = Facts::scenes();
app\Http\Controllers\Site\SolutionController.php:60: $data = Facts::scene($scene);
app\Http\Controllers\Site\SolutionController.php:65: $combo     = Facts::sceneCombo($data);
app\Http\Controllers\Site\SolutionController.php:66: $adjacent  = Facts::adjacentScenes($data);
app\Http\Controllers\Site\SolutionController.php:67: $keyProduct = ! empty($data['key_param_product']) ? Facts::product($data['key_param_product']) : null;
app\Services\Geo\LlmsBuilder.php:20: $company = Facts::company();
app\Services\Geo\LlmsBuilder.php:21: $brand   = Facts::brandLanguage();
app\Services\Geo\LlmsBuilder.php:49: $L[] = '- 销售区域：' . implode('、', Facts::salesRegions()) . '七大区域';
app\Services\Geo\LlmsBuilder.php:61: foreach (Facts::productLines() as $line) {
app\Services\Geo\LlmsBuilder.php:67: foreach (Facts::productLines() as $line) {
app\Services\Geo\LlmsBuilder.php:69: if (count(Facts::productsByLine($slug)) >= 4) {
app\Services\Geo\LlmsBuilder.php:74: foreach (Facts::products() as $p) {
app\Services\Geo\LlmsBuilder.php:75: if (! Facts::isCoreProduct($p['slug'])) {
app\Services\Geo\LlmsBuilder.php:90: foreach (Facts::scenes() as $scene) {
app\Services\Geo\LlmsBuilder.php:91: $combo = Facts::sceneCombo($scene);
app\Services\Geo\LlmsBuilder.php:99: $coop = Facts::cooperation();
app\Services\Geo\SchemaBuilder.php:98: $company = Facts::company();
app\Services\Geo\SchemaBuilder.php:118: $regions = Facts::salesRegions();
app\Services\Geo\SitemapBuilder.php:42: foreach (Facts::productLines() as $line) {
app\Services\Geo\SitemapBuilder.php:44: if (count(Facts::productsByLine($lineSlug)) >= 4) {
app\Services\Geo\SitemapBuilder.php:48: foreach (Facts::products() as $p) {
app\Services\Geo\SitemapBuilder.php:49: if (Facts::isCoreProduct($p['slug'])) {
app\Services\Geo\SitemapBuilder.php:56: foreach (Facts::scenes() as $scene) {
app\Support\HomeBlockDefaults.php:32: foreach (Facts::scenes() as $sc) {
app\Support\HomeBlockDefaults.php:35: $p = Facts::product($pslug);
app\Support\HomeBlockDefaults.php:64: }, Facts::cooperation()['types'] ?? []);
app\Support\HomeBlockDefaults.php:71: foreach (Facts::cases() as $case) {
app\Support\HomeBlockDefaults.php:74: $p = Facts::product($slug);
app\Support\HomeBlockDefaults.php:97: }, Facts::workshops());
app\Support\HomeBlockDefaults.php:103: return array_map(fn ($s) => ['title' => $s['name'], 'text' => $s['desc']], Facts::cooperation()['process'] ?? []);
app\Support\Narrative.php:158: $company = Facts::company();
app\Support\Narrative.php:190: foreach (Facts::productLines() as $line) {
app\Support\Narrative.php:191: $count = count(Facts::productsByLine($line['slug']));
app\Support\Narrative.php:205: foreach (Facts::products() as $p) {
app\Support\Narrative.php:206: if (! Facts::isCoreProduct($p['slug'])) {
app\Support\Narrative.php:226: foreach (Facts::scenes() as $scene) {
