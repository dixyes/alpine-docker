<?php

declare(strict_types=1);

// configurations

// using docker library alpine image tags
const SUPPORTED_ALPINE=[
    "3.14", // last stable should be first
    "edge",
    "3.13",
    "3.12",
    "3.11",
    "3.10",
];

const SUPPORTED_SWOOLE=[
    "4.7",
    "4.6",
    "4.5",
];

// alias rules
const PHP_RULES = [
    "START" =>[
        [ "latest" ],
        [ "DISTRO" ],
        [ "VERSION" ],
        [ "VERSION", "-", "DISTRO" ],
    ],
    "VERSION" => [
        [ "PHPMAJ", ".", "PHPMIN" ],
        [ "PHPMAJ", ".", "PHPMIN", ".", "PHPPAT" ],
    ],
    "DISTRO" => [
        [ "DISTRO_NAME" ],
        [ "DISTRO_NAME", "DISTRO_VERSION" ],
    ],
    "DISTRO_VERSION" => [
        ["-", "DISTRO_VERSION_STR" ]
    ],
];

CONST SWOOLE_RULES = [
    "START" =>[
        [ "latest" ],
        [ "VERSION", ],
        [ "VERSION", "-", "PHP_DISTRO" ],
        [ "PHP_DISTRO" ],
    ],
    "VERSION" => [
        [ "SWOOLEMAJ", ".", "SWOOLEMIN" ],
        [ "SWOOLEMAJ", ".", "SWOOLEMIN", ".", "SWOOLEPAT" ],
    ],
    "PHP_DISTRO" => [
        [ "PHP" ],
        [ "PHP", "-", "DISTRO" ],
    ],
    "PHP" => [
        [ "php-", "PHPMAJ", ".", "PHPMIN" ],
        [ "php-", "PHPMAJ", ".", "PHPMIN", ".", "PHPPAT" ],
    ],
    "DISTRO" => [
        [ "DISTRO_NAME" ],
        [ "DISTRO_NAME", "DISTRO_VERSION" ],
    ],
    "DISTRO_VERSION" => [
        [ "-", "DISTRO_VERSION_STR" ]
    ],
];

// end of configurations

class Image{
    public array $map = [];
    public string $tag;
    private string $target;
    private array $aliases;
    private array $args;
    private const SYMVER_RE = "/^(?<maj>\d+)\.(?<min>\d+)\.(?<pat>\d+)(?:-(?<ext>.+))*$/";
    public function __construct(
        public string $phpver,
        public string $distro,
        public string $distrover,
        public ?string $swoolever = null,
        public ?string $swowver = null,
    ){
        // match php version
        $ret = preg_match(static::SYMVER_RE, $phpver, $match);
        if (false === $ret){
            throw new Exception("bad version format for php: $phpver");
        }
        // prepare for alias generation
        $this->map["PHPMAJ"] = $match["maj"];
        $this->map["PHPMIN"] = $match["min"];
        $this->map["PHPPAT"] = $match["pat"];
        if(isset($match["ext"])){
            $this->map["PHPEXT"] = $match["ext"];
        }
        $this->map["DISTRO_NAME"] = $distro;
        $this->map["DISTRO_VERSION_STR"] = $distrover;
        // prepare for build image
        $this->tag = "$phpver-$distro-$distrover";
        $this->target = "php";
        $distro_upper = strtoupper($distro);
        $this->args["${distro_upper}_VERSION"] = $distrover;
        $this->args["PHP_VERSION"] = $phpver;
        if($swoolever){
            $ret = preg_match(static::SYMVER_RE, $swoolever, $match);
            if (false === $ret){
                throw new Exception("bad version format for swoole: $swoolever");
            }
            // prepare for alias generation
            $this->map["SWOOLEMAJ"] = $match["maj"];
            $this->map["SWOOLEMIN"] = $match["min"];
            $this->map["SWOOLEPAT"] = $match["pat"];
            if(isset($match["ext"])){
                $this->map["SWOOLEEXT"] = $match["ext"];
            }
            // prepare for build image
            $this->target = "swoole";
            $this->tag = "$swoolever-php-$phpver-$distro-$distrover";
            $this->args["SWOOLE_VERSION"] = $swoolever;
        }
    }
    public function setAliases(array $aliases){
        $this->aliases = $aliases;
    }
    public function build(){
        Log::i("Image hyperf/" . $this->target . ":". $this->tag);
        foreach($this->aliases as $alias){
            Log::i("\twith alias $alias");
        }
        $tags = array_merge(
            [ '-t "hyperf/' . $this->target .':' . $this->tag .'"' ],
            array_map(
                fn($tag)=>('-t "hyperf/' . $this->target .':' . $tag .'"'),
                $this->aliases
            )
        );
        $argsargs = array_map(
            fn($k,$v)=>"--build-arg '$k=$v'", array_keys($this->args),
            $this->args
        );
        $context = __DIR__ .'/' . $this->target;
        switch($this->target){
            case 'php':
                Log::i('build hyperf/php:'. $this->tag);
                $cmd = "docker build '$context' " .
                    implode(' ', $tags) . ' ' .
                    implode(' ', $argsargs);
                Log::i("running cmd: $cmd");
                passthru($cmd);
                Log::endgroup();
                break;
            case 'swoole':
                Log::i('build hyperf/swoole:' . $this->tag . ' builder image');
                $cmd = "docker build '$context' " .
                    "-f '$context/Dockerfile.builder' " .
                    '-t "hyperf/' . $this->target .':' . $this->tag .'-builder" ' .
                    implode(' ', $argsargs);
                Log::i("running cmd: $cmd");
                passthru($cmd);
                Log::endgroup();
                Log::i('build hyperf/swoole:' . $this->tag);
                $cmd = "docker build '$context' " .
                    implode(' ', $tags) . ' ' .
                    implode(' ', $argsargs);
                Log::i("running cmd: $cmd");
                passthru($cmd);
                Log::endgroup();
                Log::i('build hyperf/swoole:' . $this->tag . ' debuggable image');
                $cmd = "docker build '$context' " .
                    "-f '$context/Dockerfile.debuggable' " .
                    '-t "hyperf/' . $this->target .':' . $this->tag .'-debuggable" ' .
                    implode(' ', $argsargs);
                Log::i("running cmd: $cmd");
                passthru($cmd);
                Log::endgroup();
                break;
            default:
                throw new Exception('not supported target ' . $this->target);
        }
    }
}

class AliasGenerator{
    public function __construct(
        public array $images,
        public array $rules,
    ){
    }
    private function generate(array $tags, Image $image):array{
        $finals = [];
        while(($tag = array_pop($tags))){
            $new = null;
            for($i = 0; $i < count($tag); $i++){
                $token = $tag[$i];
                if(isset($this->rules[$token])){
                    // replace
                    $new = array_map(function($repl)use($tag, $i){
                        $new_tag = $tag;
                        array_splice($new_tag,$i,1,$repl);
                        return $new_tag;
                    },$this->rules[$token]);
                    break;
                }else if(isset($image->map[$token])){
                    // replace
                    $new_tag = $tag;
                    array_splice($new_tag,$i,1,$image->map[$token]);
                    $new = [$new_tag];
                    break;
                }
            }
            if($new){
                array_push($tags, ...$new);
                $notfinal_tags = array_map(fn($x)=>implode("|", $x), $tags);
                //echo ">>> \n". implode("\n", $notfinal_tags).PHP_EOL."<<<\n";
            }else{
                // is final
                $final_tag = implode("", $tag);
                //echo "final ".$image->tag." => $final_tag\n";
                array_unshift($finals,$final_tag);
            }
        }
        return $finals;
    }
    public function make():\WeakMap{
        $used = [];
        $map = new \WeakMap();
        foreach($this->images as $image){
            $tags = $this->generate([["START"]], $image);
            $real_tags = [];
            foreach($tags as $tag){
                // echo $image->tag ." => $tag\n";
                if(isset($used[$tag])){
                    continue;
                }
                $used[$tag] = true;
                $real_tags[]=$tag;
            }
            $map[$image]=$real_tags;
        }
        return $map;
    }
}

class Log{
    private static self $logger;
    private bool $ci;
    private bool $tty;
    private function __construct(){
        $this->ci = getenv('CI') === 'true';
        $this->tty = stream_isatty(STDOUT) || $this->ci;
    }
    public static function init(){
        if(!isset(static::$logger)){
            static::$logger = new static();
        }
    }
    private function log(string $prefix, ?string $color, array $args){
        printf(
            "%s%s%s%s\n",
            $this->tty ? ($color?:'') : '',
            $prefix,
            $this->tty ? "\033[0m" : '',
            implode(' ', $args)
        );
    }
    public static function endgroup(){
        if(static::$logger->ci){
            printf("::endgroup::\n");
        }
    }
    public static function group(...$args){
        if(static::$logger->ci){
            static::$logger->log('::group::', null, $args);
        }else{
            static::i(...$args);
        }
    }
    public static function i(...$args){
        Log::$logger->log('[IFO] ', "\033[32;1m", $args);
    }
    public static function w(...$args){
        Log::$logger->log('[WRN] ', "\033[33;1m", $args);
    }
    public static function e(...$args){
        Log::$logger->log('[ERR] ', "\033[31;1m", $args);
    }
}

abstract class VersionUpdater{
    abstract static public function imageArgs():array;
    abstract public function versions(bool $update=false):array;
    public function list(){
        $imageArgs = $this->imageArgs();
        $versions = $this->versions();
        //var_dump($versions);
        $ret = [];

        $it = new \RecursiveIteratorIterator(
            new \RecursiveArrayIterator($versions),
            \RecursiveIteratorIterator::SELF_FIRST
        );
        foreach($it as $k => $v) {
            if([] !== $v){
                continue;
            }
            // pop out
            $ver = [];
            for($i=$it->getDepth();$i>=0;$i--){
                array_unshift($ver, $it->getSubIterator($i)->key());
            }
            $args = array_merge(array_combine($imageArgs['versions'], $ver), $imageArgs['extra']);
            array_push($ret, new Image(...$args));

        }
        return $ret;
    }
}

class PHPAlpineUpdater extends VersionUpdater{
    static public function imageArgs():array{
        return [
            "versions" => ["distrover", "phpver"],
            "extra" => ["distro" => "alpine"]
        ];
    }
    public function __construct(
        private ?array $parent_versions = null
    ){
    }
    private ?array $versions = null;
    public function versions(bool $update=false):array{
        if (!$update && $this->versions){
            return $this->versions;
        }
        $this->versions = [];
        foreach(SUPPORTED_ALPINE as $alpinever){
            Log::group("Pull alpine:$alpinever");
            //passthru("docker pull alpine:$alpinever");
            Log::endgroup();
            $cmd = "docker run --rm alpine:$alpinever sh -c '" . 
                //'set -x &&' .
                // TODO fuck remove this
                'sed -i "s/dl-cdn.alpinelinux.org/mirrors.ustc.edu.cn/g" /etc/apk/repositories &&' .
                //'sed -i "s/dl-cdn.alpinelinux.org/mirrors.tuna.tsinghua.edu.cn/g" /etc/apk/repositories &&' .
                'apk update >&2 && { ' .
                    'p7=$(apk list php7)||:;' . 
                    'p8=$(apk list php8)||:;' .
                    'echo ${p8%% *} ${p7%% *};' .
                '}' .
            "'";
            $proc = proc_open(
                $cmd,
                [
                    0 => STDIN,
                    1 => ["pipe", "w"],
                    2 => STDERR
                ],
                $pipes
            );
            $phps = "";
            Log::group("Find php versions on $alpinever");
            while(($status = proc_get_status($proc)) && $status["running"]){
                $phps.=fread($pipes[1], 4096);
                usleep(500 * 1000 /* 500ms */);
            }
            $phppkgs = explode(" ", $phps);
            foreach($phppkgs as $phppkg){
                $x = explode("-", $phppkg);
                Log::i("found PHP in $alpinever:", $x[1]);
                $this->versions[$alpinever][$x[1]] = [];
            }
            Log::endgroup();
        }
        return $this->versions;
    }
}

class SwoolePHPAlpineUpdater extends PHPAlpineUpdater{
    static public function imageArgs():array{
        return [
            "versions" => ["distrover", "phpver", "swoolever"],
            "extra" => ["distro" => "alpine"]
        ];
    }
    private const SWOOLE_REPO = 'swoole/swoole-src';
    private const MIN_SUPPORT_VERSION = [
        '4.6.0' => '8.0',
        '4.4.2' => '7.4',
        '4.0.2' => '7.3',
    ];
    public function __construct(
        private ?array $parent_versions = null
    ){
    }
    private ?array $versions = null;
    public function versions(bool $update=false):array{
        if(!$this->parent_versions){
            $this->parent_versions = parent::versions($update);
        }
        if($this->versions && !$update){
            return $this->versions;
        }
        Log::i('find latest swoole versions');
        $headers = "accept: application/vnd.github.v3+json\r\n" .
            "user-agent: not-a-bad-script/0.1\r\n".
            "content-type: application/json\r\n";
        if(getenv('GITHUB_TOKEN')){
            $headers .= 'authorization: Bearer ' . getenv('GITHUB_TOKEN') . "\r\n";
        }
        $opts = [
            'http' => [
                'method' => 'GET',
                'header' => $headers
            ]
        ];
        $context = stream_context_create($opts);
        //$tags = file_get_contents('https://api.github.com/repos/' . static::SWOOLE_REPO . '/git/refs/tags', false, $context);
        $tags = file_get_contents('/tmp/a.json');
        if(!$tags){
            throw new Exception('failed get tags info');
        }
        $tags = json_decode($tags, true);
        // set(tags) => set(swoole versions)
        $latest_versions = array_fill_keys(SUPPORTED_SWOOLE, "0.0.0");
        foreach($tags as $tag){
            $tagname = substr($tag['ref'], 10);
            foreach(SUPPORTED_SWOOLE as $swoole){
                if(!preg_match("/^v(?<ver>(?<majmin>\d+\.\d+)\.\d+)$/", $tagname, $match)){
                    continue;
                }
                if($match['majmin'] !== $swoole){
                    continue;
                }
                if(version_compare($match['ver'],$latest_versions[$swoole],">")){
                    $latest_versions[$swoole] = $match['ver'];
                }
            }
        }
        // set(swoole versions) => map(min php version => swoole verison)
        $latest_php_versions=[];
        foreach($latest_versions as $branch => $swoolever){
            Log::i("use $swoolever for $branch");
            foreach(static::MIN_SUPPORT_VERSION as $minswver => $minphpver){
                if(version_compare($minswver, $swoolever, ">")){
                    continue;
                }
                Log::i("enable php $minphpver for $swoolever");
                $latest_php_versions[$minphpver]=$swoolever;
            }
        }
        // map(min php version => swoole verison) => tree(alpine ver, php ver, swoole ver)
        $this->versions = $this->parent_versions;
        foreach($this->versions as $alpine => $phps){
            foreach($phps as $php => $_){
                foreach($latest_php_versions as $minphpver => $swoolever){
                    if(version_compare($php, $minphpver,">")){
                        $this->versions[$alpine][$php][$swoolever] = [];
                    }
                }
            }
        }
        return $this->versions;
    }
}

function usage(string $self):int{
    echo "usage: $self buildall\n";
    return 1;
}

function all(
    string $action,
    string $target,
    string $updaterClass,
    array $rules,
    ?array &$output,
    ?array $parent_versions=null
):array {
    $updater = new $updaterClass($parent_versions);
    $versions = $updater->versions();
    $g = new AliasGenerator($updater->list(), $rules);
    $aliases = $g->make();
    if('list' === $action){
        if(!$output){
            $output = [];
        }
        foreach($aliases as $image => $aliases){
            $task=[
                'imagename'=> "hyperf/$target:" . $image->tag,
                'phpver'=> $image->phpver,
                'distrover'=> $image->distrover,
                'distro'=> $image->distro,
                'aliases' => $aliases,
            ];
            if($target === 'swoole'){
                $task['swoolever'] = $image->swoolever;
            }
            $output[]=$task;
        }
    }else {
        foreach($aliases as $image => $alias){
            $image->setAliases($alias);
            $image->build();
        }
    }
    return $versions;
}

function mian(array $argv):int{
    Log::init();
    $self = $argv[0];
    $action = $argv[1];
    switch($action){
        case 'list':
        case 'buildall':
            $target = isset($argv[2])? $argv[2]:'all';
            $versions = null;
            switch($target){
                case 'all':
                case 'php':
                    $versions = all($action, 'php', PHPAlpineUpdater::class, PHP_RULES, $output);
                    if('all' !== $target){
                        break;
                    }
                case 'swoole':
                    all($action, 'swoole', SwoolePHPAlpineUpdater::class, SWOOLE_RULES, $output, $versions);
                    break;
                default:
                    return usage($self);
            }
            if('list' === $action){
                if(isset($argv[3])){
                    file_put_contents($argv[3], json_encode($output));
                }else{
                    fwrite(STDOUT, json_encode($output, JSON_PRETTY_PRINT));
                }
            }
            break;
        case 'build':
            $target = $argv[2];
            $args = array_slice($argv, 2);
            $imageArgs = [];
            $aliases = [];
            foreach($args as $arg){
                [$k, $v]=explode("=", $arg, 2);
                switch($k){
                    case 'distro':
                    case 'distrover':
                    case 'phpver':
                    case 'swoolever':
                    case 'swowver':
                        $imageArgs[$k] = $v;
                        break;
                    case 'aliases':
                        $aliases = implode(",", $v);
                        break;
                    default:
                        Log::e("unknown option", $k);
                        return usage($self);
                }
            }
            if(!isset($imageArgs['distro'])){
                $imageArgs['distro'] = 'alpine';
            }
            if(
                !isset($imageArgs['phpver']) ||
                !isset($imageArgs['distrover'])
            ){
                Log::e("lacking phpver or distrover");
                return usage($self);
            }
            $image = new Image(...$imageArgs);
            $image->setAliases($aliases);
            $image->build();
            break;
        default:
            return usage($self);
    }
    return 0;
}

exit(mian($argv));
