<?php
/**
 * ShopEx licence
 *
 * @copyright  Copyright (c) 2005-2010 ShopEx Technologies Inc. (http://www.shopex.cn)
 * @license  http://ecos.shopex.cn/ ShopEx License
 */

use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use base_pipeline_pipeline as Pipeline;

//error_reporting(E_ALL ^ E_NOTICE);

// ego version
if(file_exists(ROOT_DIR.'/app/base/ego/ego.php')){
    require_once(ROOT_DIR.'/app/base/ego/ego.php');
}

class kernel
{
    static $base_url = null;
    static $url_app_map = array();
    static $app_url_map = array();
    static private $__online = null;
    static private $__db_instance = null;
    static private $__singleton_instance = array();
    static private $__request_instance = null;
    static private $__single_apps = array();
    static private $__service_list = array();
    static private $__base_url = array();
    static private $__language = null;
    static private $__service = array();
    static private $__require_config = null;
    static private $__host_mirrors = null;
    static private $__host_mirrors_count = null;
    static private $__host_mirrors_img = null;
    static private $__host_mirrors_img_count = null;
    static private $__exception_instance = null;
    static private $__routeMiddleware = [];
    static private $__middleware = [];
    static private $__running_in_console = null;


    static public function startExceptionHandling()
    {
        self::exceptionBootstrap()->bootstrap();
    }

    static public function exceptionBootstrap()
    {
        if(!isset(self::$__exception_instance))
        {
            self::$__exception_instance = kernel::single('base_foundation_bootstrap_handleExceptions');
        }
        return self::$__exception_instance;
    }

    static function boot(){
        $pathinfo = request::getPathInfo();

        // 生成part
        if(isset($pathinfo{1})){
            if($p = strpos($pathinfo,'/',2)){
                $part = substr($pathinfo,0,$p);
            }else{
                $part = $pathinfo;
            }
        }else{
            $part = '/';
        }

        if ($part=='/openapi'){
            return kernel::single('base_rpc_service')->process($pathinfo);
        }elseif($part=='/app-doc'){
            //cachemgr::init();
            return kernel::single('base_misc_doc')->display($pathinfo);
        }

        // 确认是否安装流程. 如果是安装流程则开启debug. 如果不是则检查是否安装, 如果未安装则跳到安装流程
        // 目前其他的url, 都应移到routes中进行
        //
        if ($part=='/setup')
        {
            config::set('app.debug', true);
        }
        else
        {
            static::checkInstalled();
        }

        static::registRouteMiddleware();
        
        //$response = route::dispatch(request::instance());
        $response = static::sendRequestThroughRouter(request::instance());
        // 临时处理方式
        kernel::single('base_session')->close();
        $response->send();

    }

    /**
     * Send the given request through the middleware / router.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    static public function sendRequestThroughRouter($request) {
        return (new Pipeline())
                    ->send($request)
                    ->through(static::$__middleware)
                    ->then(static::dispatchToRouter());
    }

    /**
     * Get the route dispatcher callback.
     *
     * @return \Closure
     */
    protected function dispatchToRouter()
    {
        return function ($request) {
            return route::dispatch($request);
        };
    }
    

    static public function registRouteMiddleware()
    {
        foreach (static::$__routeMiddleware as $key => $middleware)
        {
            route::middleware($key, $middleware);
        }
    }

    static function checkInstalled() {
        if(!self::is_online()){
            if(file_exists(APP_DIR.'/setup/app.xml')){
                //todo:进入安装check
                setcookie('LOCAL_SETUP_URL', url::route('setup'), 0, '/');
                if (file_exists(PUBLIC_DIR.'/check.php'))
                {
                    header('Location: '. kernel::base_url().'/check.php');
                }
                else
                {
                    header('Location: '. url::route('setup'));
                }
                exit;
            }else{
                echo '<h1>System is Offline, install please.</h1>';
                exit;
            }
        }
    }

	static function openapi_url($openapi_service_name,$method='access',$params=null){
        if(substr($openapi_service_name,0,8)!='openapi.'){
            trigger_error('$openapi_service_name must start with: openapi.');
            return false;
        }
        $arg = array();
        foreach((array)$params as $k=>$v){
            $arg[] = urlencode($k);
            $arg[] = urlencode(str_replace('/','%2F',$v));
        }
        return kernel::base_url(1).kernel::url_prefix().'/openapi/'.substr($openapi_service_name,8).'/'.$method.'/'.implode('/',$arg);
        }

    static function removeIndex($root) {
        $i = 'index.php';

		return str_contains($root, $i) ? str_replace('/'.$i, '', $root) : $root;
    }


    static function url_prefix(){
        return (defined('WITH_REWRITE') && WITH_REWRITE === true)?'':'/index.php';
    }

    static public function get_host_mirror(){
        $host_mirrors = (array)config::get('storager.host_mirrors', array());
        if(!empty($host_mirrors)){
            if (!isset(self::$__host_mirrors)) {
                self::$__host_mirrors = $host_mirrors;
                self::$__host_mirrors_count = count($host_mirrors)-1;
            }
			return self::$__host_mirrors[rand(0, self::$__host_mirrors_count)];
		}
		return false;
    }

    static public function get_host_mirror_img($hostName=null)
    {
        $hostMirrors = (array)config::get('storager.host_mirrors_img', array());

        if( !is_null($hostName) )
        {
            return isset($hostMirrors[$hostName]) ? $hostMirrors[$hostName] : self::removeIndex(kernel::base_url(1));
        }
        else
        {
            $hostMirrorsKey = array_keys($hostMirrors);
            if(!empty($hostMirrorsKey))
            {
                if (!isset(self::$__host_mirrors_img))
                {
                    self::$__host_mirrors_img = $hostMirrorsKey;
                    self::$__host_mirrors_img_count = count($hostMirrorsKey)-1;
                }
                return self::$__host_mirrors_img[rand(0, self::$__host_mirrors_img_count)];
            }
        }
    }

	static function get_resource_host_url($local_flag = false){
        $host = static::get_host_mirror($local_flag)?:kernel::base_url(1);
        return $host;
	}

    static function get_themes_host_url($local_flag = false){
        //return kernel::get_resource_host_url($local_flag).substr(THEME_DIR, strlen(ROOT_DIR));
        return kernel::get_resource_host_url($local_flag).substr(THEME_DIR, strlen(PUBLIC_DIR));
    }

	static function get_app_statics_host_url($local_flag = false){
        //return kernel::get_resource_host_url($local_flag).substr(PUBLIC_DIR, strlen(ROOT_DIR)).'/app';
        return kernel::get_resource_host_url($local_flag).'/app';
	}
	//APP_STATICS_HOST

    static function base_url($full=false){
        $c = ($full) ? 'true' : 'false';
        if(!isset(self::$__base_url[$c]) || defined('BASE_URL')){
            if(defined('BASE_URL')){

                if($full){
                    self::$__base_url[$c] = constant('BASE_URL');
                }else{
                    $url = parse_url(constant('BASE_URL'));
                    if(isset($url['path'])){
                        self::$__base_url[$c] = $url['path'];
                    }else{
                        self::$__base_url[$c] = '';
                    }
                }
            }else{
                if(!isset(self::$base_url)){
                    self::$base_url = static::removeIndex(request::getBaseUrl());
                    // 目前的方式是保持request的纯洁性. 在base_url中做特殊处理.
                }

                if(self::$base_url == '/'){
                    self::$base_url = '';
                }

                if($full){
                    self::$__base_url[$c] = static::removeIndex(request::root());
                }else{
                    self::$__base_url[$c] = self::$base_url;
                }
            }
        }

        return self::$__base_url[$c];
    }

    static function set_online($mode){
        self::$__online = $mode;
    }

    static function is_online()
    {
        if(self::$__online===null){
            self::$__online = file_exists(ROOT_DIR.'/config/install.lock.php' );
        }
        return self::$__online;
    }

    static function single($class_name,$arg=null){
        if(is_object($arg)) {
            $key = get_class($arg);
            if($key==='app'){
                $key .= '.' . $arg->app_id;
            }
            $key = '__class__' . $key;
        }else{
            $key = md5('__key__'.serialize($arg));
        }
        if(!isset(self::$__singleton_instance[$class_name][$key])){
            self::$__singleton_instance[$class_name][$key] = new $class_name($arg);
        }
        return self::$__singleton_instance[$class_name][$key];
    }

    static function service($srv_name,$filter=null){
        return self::servicelist($srv_name,$filter)->current();
    }

    static function servicelist($srv_name,$filter=null){
        $service_define = syscache::instance('service')->get($srv_name);
        if (!is_null($service_define)) {
            return new service($service_define,$filter);
        }else{
            return new ArrayIterator(array());
        }
	}

    static public function set_lang($language)
    {
        self::$__language = trim($language);
    }//End Function

    static public function get_lang()
    {
        return  self::$__language ? self::$__language : ((defined('LANG')&&constant('LANG')) ? LANG : 'zh_CN');
    }//End Function

    static function getCachedRoutesPath()
    {
        return ROOT_DIR.'/vendor/routes.php';
    }

    static function routesAreCached()
    {
        return kernel::single('base_filesystem')->exists(static::getCachedRoutesPath());
    }

	/**
	 * Throw an HttpException with the given data.
	 *
	 * @param  int     $code
	 * @param  string  $message
	 * @param  array   $headers
	 * @return void
	 *
	 * @throws \Symfony\Component\HttpKernel\Exception\HttpException
	 */
    static function abort($code, $message = '', array $headers = array())
    {
		if ($code == 404)
		{
			throw new NotFoundHttpException($message);
		}

		throw new HttpException($code, $message, null, $headers);
    }

    static public function runningInConsole()
    {
        if (static::$__running_in_console == null) {
            return php_sapi_name() == 'cli';
        }

        return static::$__running_in_console;
        //return php_sapi_name() == 'cli';
    }

    static public function simulateRunningInConsole()
    {
        static::$__running_in_console = true;
    }

    static public function environment()
    {
        return 'production';
    }

   static public function pushMiddleware($middleware)
   {
       if (array_search($middleware, static::$__routeMiddleware) === false) {
           static::$__middleware[] = $middleware;
       }
   }
}

if (!function_exists('__') ) {
    function __($str){
        return $str;
    }
}
