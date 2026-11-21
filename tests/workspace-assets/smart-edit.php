<?php

declare(strict_types=1);

use app\api\controller\WorkspaceAssetController;
use app\common\service\WorkspaceAssetService;
use think\db\BaseQuery;
use think\facade\Db;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

/** SQLite spelling for the existing workspace metadata upsert. All fixture rows stay in memory. */
final class SmartEditTestSqliteBuilder extends \think\db\builder\Sqlite
{
    protected function parseExtra(BaseQuery $query, string $extra): string
    {
        return strtoupper($extra) === 'IGNORE' ? ' OR IGNORE' : parent::parseExtra($query, $extra);
    }
}

$app = new \think\App(dirname(__DIR__, 2));
$app->setRuntimePath(sys_get_temp_dir() . '/work-smart-edit-test-' . bin2hex(random_bytes(5)) . '/');
$app->initialize();
set_exception_handler(static function (Throwable $error): void {
    fwrite(STDERR, (string)$error . PHP_EOL);
    exit(1);
});
$app->config->set(['default' => 'test', 'connections' => ['test' => [
    'type' => 'sqlite', 'database' => ':memory:', 'prefix' => 'la_', 'builder' => SmartEditTestSqliteBuilder::class,
]]], 'database');
Db::execute('CREATE TABLE la_tenant(id INTEGER PRIMARY KEY,sn TEXT,tactics INTEGER,delete_time INTEGER)');
Db::execute("INSERT INTO la_tenant VALUES (1,'shared',0,NULL),(2,'separate',1,NULL)");
Db::execute('CREATE TABLE la_user_asset_state(id INTEGER PRIMARY KEY,tenant_id INTEGER NOT NULL,user_id INTEGER NOT NULL,
    asset_ref TEXT NOT NULL, favorite INTEGER DEFAULT 0,label TEXT DEFAULT \'\',trashed_at INTEGER DEFAULT 0,hidden_at INTEGER DEFAULT 0,
    create_time INTEGER,update_time INTEGER,UNIQUE(tenant_id,user_id,asset_ref))');
Db::execute('CREATE TABLE la_user_asset_labels(tenant_id INTEGER,user_id INTEGER,labels TEXT,update_time INTEGER)');
$columns = '(id INTEGER PRIMARY KEY, tenant_id INTEGER,user_id INTEGER,status TEXT,output_uri TEXT,storage_domain TEXT,
    cover_uri TEXT,duration REAL,title TEXT,create_time INTEGER,delete_time INTEGER)';
Db::execute('CREATE TABLE la_smart_edit_task ' . $columns);
Db::execute('CREATE TABLE la_smart_edit_task_separate ' . $columns);
Db::connect()->getPdo()->sqliteCreateFunction('CONCAT', static fn (...$args) => implode('', $args));

function verify(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

function libraryCall(string $action, array $params = [], int $tenant = 2, int $user = 7): array
{
    global $app;
    $request = new \app\Request();
    $request->withServer(['REQUEST_METHOD' => $action === 'update' ? 'POST' : 'GET'])
        ->withGet($params)->withPost($params);
    $request->tenantId = $tenant;
    $request->userId = $user;
    $request->userInfo = ['tenant_id' => $tenant, 'user_id' => $user];
    $app->instance('request', $request);
    return (new WorkspaceAssetController($app))->$action()->getData();
}

function listPage(array $params = [], int $tenant = 2, int $user = 7): array
{
    $response = libraryCall('smartEdit', $params, $tenant, $user);
    verify($response['code'] === 1, 'Smart edit list failed: ' . $response['msg']);
    return $response['data'];
}

foreach ([
    [1,7,'success','final/a.mp4','A%门店',null],
    [2,7,'processing','final/b.mp4','B',null],
    [3,7,'success','','Missing URI',null],
    [4,7,'success','final/d.mp4','Deleted',123],
    [5,9,'success','final/e.mp4','Other user',null],
    [6,7,'success','final/f.mp4','Hidden',null],
    [7,7,'success','final/g.mp4','Trashed',null],
    [8,7,'success','final/h.mp4','Newest',null],
] as [$id,$user,$status,$uri,$title,$deleteTime]) {
    Db::name('smart_edit_task_separate')->insert([
        'id'=>$id,'tenant_id'=>2,'user_id'=>$user,'status'=>$status,'output_uri'=>$uri,
        'title'=>$title,'create_time'=>100+$id,'storage_domain'=>'https://assets.example','duration'=>2.5,
        'cover_uri'=>'covers/first.jpg','delete_time'=>$deleteTime,
    ]);
}
Db::name('smart_edit_task')->insert([
    'id'=>1,'tenant_id'=>1,'user_id'=>7,'status'=>'success','output_uri'=>'other.mp4','storage_domain'=>'https://other.example',
    'cover_uri'=>'','duration'=>1,'title'=>'Other tenant','create_time'=>200,
]);
foreach ([6=>'hidden_at',7=>'trashed_at'] as $id=>$flag) {
    Db::name('user_asset_state')->insert(['tenant_id'=>2,'user_id'=>7,'asset_ref'=>'smart_edit:task:'.$id,$flag=>100]);
}

$page = listPage(['page_size'=>1]);
verify($page['count'] === 2 && $page['has_more'] && $page['lists'][0]['ref'] === 'smart_edit:task:8', 'visibility before pagination');
$next = listPage(['page_no'=>2,'page_size'=>1]);
verify(!$next['has_more'] && $next['lists'][0]['ref'] === 'smart_edit:task:1', 'stable second page');
$item = $next['lists'][0];
verify($item['raw']['video_url'] === 'https://assets.example/final/a.mp4'
    && $item['raw']['cover_url'] === 'https://assets.example/covers/first.jpg'
    && $item['raw']['duration'] === 2.5 && $item['raw']['task_id'] === 1, 'playback URL, cover and identity');
verify(listPage(['keyword'=>'%'])['lists'] === [$item], 'title search treats wildcards literally');
verify(listPage([],1)['lists'][0]['raw']['title'] === 'Other tenant', 'tenant shard and owner isolation');
verify(listPage([],2,9)['count'] === 1, 'user isolation');

Db::name('smart_edit_task_separate')->where('id',2)->update(['status'=>'success']);
verify(listPage()['count'] === 3 && listPage()['count'] === 3, 'newly completed task appears once');
verify(Db::name('user_asset_state')->count() === 2, 'list did not create state rows');
$ref = 'smart_edit:task:2';
verify(WorkspaceAssetService::visibleAsset(2,7,$ref)['ref'] === $ref, 'eligible task ref resolves');
verify(WorkspaceAssetService::visibleAsset(2,7,'smart_edit:result:2') === null, 'unrecognized result ref');
verify(WorkspaceAssetService::visibleAsset(2,9,$ref) === null, 'foreign user cannot read task');
verify(libraryCall('update',['refs'=>[$ref],'action'=>'trash'],2,9)['code'] !== 1, 'foreign user cannot trash');
verify(libraryCall('update',['refs'=>[$ref],'favorite'=>1])['code'] === 1, 'favorite a finished task');
verify(libraryCall('update',['refs'=>[$ref],'action'=>'trash'])['code'] === 1, 'trash');
verify(listPage()['count'] === 2 && WorkspaceAssetService::visibleAsset(2,7,$ref) === null, 'trashed task hidden');
verify((int)Db::name('smart_edit_task_separate')->where('id',2)->value('delete_time') > 0, 'source task soft deleted');
$state = libraryCall('state');
verify($state['code'] === 1 && in_array($ref, array_column($state['data']['supplemental'], 'ref'), true), 'trash remains available for restore');
verify(libraryCall('update',['refs'=>[$ref],'action'=>'restore'])['code'] === 1, 'restore');
verify(listPage()['count'] === 3 && Db::name('smart_edit_task_separate')->where('id',2)->value('delete_time') === null, 'restore sets NULL');
verify(libraryCall('update',['refs'=>[$ref],'action'=>'trash'])['code'] === 1, 'trash again');
verify(libraryCall('update',['refs'=>[$ref],'action'=>'remove'])['code'] === 1, 'permanent library removal');
verify(listPage()['count'] === 2 && WorkspaceAssetService::visibleAsset(2,7,$ref) === null, 'removed task remains hidden');

echo "PASS: smart edit workspace listing, live completion, keyword, pagination, sharded ownership, favorite and trash lifecycle\n";
