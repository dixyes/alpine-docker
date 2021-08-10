<?php
/**
 * Hyperf alpine docker command-line tool hyperf-docker.php
 * run "php hyperf-docker.php help" to see usage
 * php version 8.0.0
 * @link     https://www.hyperf.io
 * @document https://hyperf.wiki
 * @contact  group@hyperf.io
 * @license  https://github.com/hyperf/hyperf/blob/master/LICENSE
 */

declare(strict_types=1);

// {{ configurations

/*
 * using docker library alpine image tags
 * should be string[]
 */
const SUPPORTED_ALPINE = [
    '3.14', // last stable should be first
    'edge',
    '3.13',
    '3.12',
    '3.11',
    '3.10',
];

/*
 * will be latest sub-versions of this major.minor
 * should be string[]
 */
const SUPPORTED_SWOOLE = [
    '4.7',
    '4.6',
    '4.5',
];

/*
 * will use head of these branches
 * should be string[]
 */
const SUPPORTED_SWOOLE_BRANCHES = [
    'master',
];

/*
 * will be latest sub-versions of this major.minor
 * should be string[]
 */
const SUPPORTED_SWOW = [
    '0.1',
];

/*
 * will use head of these branches
 * should be string[]
 */
const SUPPORTED_SWOW_BRANCHES = [
    'develop',
    'ci',
];

// }}

class Log
{
    private static self $logger;

    private bool $ci;

    private bool $tty;

    private function __construct()
    {
        $this->ci = getenv('CI') === 'true';
        $this->tty = stream_isatty(STDOUT) || $this->ci;
    }

    public static function init(): void
    {
        if (!isset(static::$logger)) {
            static::$logger = new static();
        }
    }

    private function log(string $prefix, ?string $color, array $args): void
    {
        $_prefix = '';
        if ($this->tty) {
            $_prefix = "{$color}{$prefix}\033[0m";
        }
        echo $_prefix . implode(' ', $args) . PHP_EOL;
    }

    public static function endgroup(): void
    {
        if (static::$logger->ci) {
            echo '::endgroup::' . PHP_EOL;
        }
    }

    public static function group(...$args): void
    {
        if (static::$logger->ci) {
            static::$logger->log('::group::', null, $args);
        } else {
            static::i(...$args);
        }
    }

    public static function i(...$args): void
    {
        Log::$logger->log('[IFO] ', "\033[32;1m", $args);
    }

    public static function w(...$args): void
    {
        Log::$logger->log('[WRN] ', "\033[33;1m", $args);
    }

    public static function e(...$args): void
    {
        Log::$logger->log('[ERR] ', "\033[31;1m", $args);
    }
}

class Image
{
    public const PHP_RULES = [
        'START' => [
            ['latest'],
            ['DISTRO'],
            ['VERSION'],
            ['VERSION', '-', 'DISTRO'],
        ],
        'VERSION' => [
            ['PHP_VERSION'],
        ],
        'DISTRO' => [
            ['DISTRO_NAME'],
            ['DISTRO_NAME', '-', 'DISTRO_VERSION'],
        ],
    ];

    public const EXT_RULES = [
        'START' => [
            ['latest'],
            ['VERSION'],
            ['VERSION', '-', 'PHP_DISTRO'],
            ['PHP_DISTRO'],
        ],
        'VERSION' => [
            ['EXT_VERSION'],
        ],
        'PHP_DISTRO' => [
            ['PHP'],
            ['PHP', '-', 'DISTRO'],
        ],
        'PHP' => [
            ['php-', 'PHP_VERSION'],
        ],
        'DISTRO' => [
            ['DISTRO_NAME'],
            ['DISTRO_NAME', '-', 'DISTRO_VERSION'],
        ],
    ];

    /* @var string[] */
    private static array $usedAliases = [];

    /* @var string[] */
    private static array $commitCache = [];

    /* @var array[][] */
    private static array $commitMap = [
        'swoole' => [],
        'swow' => [],
    ];

    private ?string $commit = null;

    private string $tag;

    private string $target;

    /* @var string[] */
    private array $buildArgs = [];

    /* @var array[] */
    private array $rules = [];

    private const SYMVER_RE =
        '/^(?<maj>\\d+)\\.(?<min>\\d+)\\.(?<pat>\\d+)(?:-(?<ext>.+))*$/';

    public ?Image $duplicationOf = null;

    /**
     * @throws Exception
     */
    public function __construct(
        public string $phpver,
        public string $distro,
        public string $distrover,
        public ?string $ext = null,
        public ?string $extver = null,
        public ?string $composer = null,
        /* @var string[] */
        private ?array $aliases = null,
        private array $extra = [],
    ) {
        // match php version
        $ret = preg_match(static::SYMVER_RE, $phpver, $match);
        if ($ret < 1) {
            throw new Exception("bad version format for php: {$phpver}");
        }
        // prepare for alias generation
        $this->rules += [
            'PHP_VERSION' => [
                [$match['maj'], '.', $match['min']],
                [$phpver],
            ],
            'DISTRO_NAME' => [[$distro]],
            'DISTRO_VERSION' => [[$distrover]],
        ];
        // prepare for build image
        $this->buildArgs += [
            strtoupper($distro) . '_VERSION' => $distrover,
            'PHP_VERSION' => $phpver,
        ];
        if (!$ext) {
            $this->target = 'php';
            $this->tag = "{$phpver}-{$distro}-{$distrover}";
            $this->rules += static::PHP_RULES;

            return;
        }
        // extensions
        // prepare for build image
        $this->target = $ext;
        $this->tag = "{$extver}-php-{$phpver}-{$distro}-{$distrover}";
        $this->rules += static::EXT_RULES;
        // prepare for alias generation
        $extUpper = strtoupper($ext);
        $this->buildArgs["{$extUpper}_VERSION"] = $extver;
        if ($extver === 'master' || $extver == 'ci' || $extver == 'develop') {
            $this->rules['EXT_VERSION'] = [
                [$extver],
            ];
            $ref = $extver;
        } else {
            $ret = preg_match(static::SYMVER_RE, $extver, $match);
            if ($ret < 1) {
                throw new Exception("bad version format for extension: {$extver}");
            }
            $this->rules['EXT_VERSION'] = [
                [$match['maj'], '.', $match['min']],
                [$extver],
            ];
            $ref = "v{$extver}";
        }
        $repo = $ext === 'swoole' ? 'swoole/swoole-src' : 'swow/swow';
        $k = "{$ext}-{$ref}";
        if (!isset(static::$commitCache[$k])) {
            $commit = GithubAPI::req("repos/{$repo}/commits/{$ref}");
            static::$commitCache[$k] = $commit['sha'];
        }
        $this->commit = static::$commitCache[$k];
        $this->buildArgs["{$extUpper}_FN"] = $this->commit;
        $this->rules['EXT_VERSION'][] = [$this->commit];
        $this->rules['EXT_VERSION'][] = [substr($this->commit, 0, 8)];
        if (!isset($composer)) {
            throw new Exception('composer version is not set');
        }
        $this->buildArgs['COMPOSER_VERSION'] = $composer;
    }

    public function genAliases(): void
    {
        if ($this->aliases !== null) {
            return;
        }
        //Log::i("gen aliases for {$this->target}:{$this->tag}");
        // "tag" is an array of tokens
        $tags = [['START']];
        $finals = [];
        while (($tag = array_pop($tags))) {
            // for each tag
            $new = null;
            foreach ($tag as $i => $token) {
                $rule = $this->rules[$token] ?? null;
                if ($rule === null) {
                    continue;
                }
                // if there's a rule, apply it
                $new = array_map(function ($repltag) use ($tag, $i) {
                    $new_tag = $tag;
                    array_splice($new_tag, $i, 1, $repltag);

                    return $new_tag;
                }, $rule);
                break;
            }
            if ($new) {
                // not final
                // push all extened tags back
                array_push($tags, ...$new);
            } else {
                // is final
                // pop tag out
                $tagName = implode('', $tag);
                $fullName = "hyperf/{$this->target}:{$tagName}";
                if (isset(static::$usedAliases[$fullName])) {
                    // one shot only
                    continue;
                }
                static::$usedAliases[$fullName] = true;
                //Log::i("    {$tagName}");
                array_unshift($finals, $tagName);
            }
        }
        if ($this->commit) {
            $b = "{$this->ext}-{$this->phpver}-{$this->distrover}";
            if (isset(static::$commitMap[$b][$this->commit])) {
                $real_image = static::$commitMap[$b][$this->commit];
                $this->aliases = [];
                array_push($real_image->aliases, ...$finals);
                $this->duplicationOf = $real_image;
            } else {
                static::$commitMap[$b][$this->commit] = $this;
            }
        }

        $this->aliases = $finals;
    }

    /**
     * @throws Exception
     */
    private static function runCmd(string $cmd): void
    {
        Log::i("running cmd: {$cmd}");
        passthru($cmd, $ret);
        if ($ret !== 0) {
            throw new Exception("failed run command {$cmd}");
        }
    }

    /**
     * @throws Exception
     */
    public function build(): void
    {
        $this->genAliases();
        $mainTag = "hyperf/{$this->target}:{$this->tag}";
        Log::i("Image {$mainTag}");
        foreach ($this->aliases as $alias) {
            Log::i("\twith alias {$alias}");
        }
        $tags = array_merge(
            ["-t '{$mainTag}'"],
            array_map(
                fn ($tag) => ("-t 'hyperf/{$this->target}:{$tag}'"),
                $this->aliases
            )
        );
        $argsArgs = array_map(
            fn ($k, $v) => "--build-arg '{$k}={$v}'",
            array_keys($this->buildArgs),
            $this->buildArgs
        );
        $context = __DIR__ . '/' . $this->target;
        switch ($this->target) {
            case 'php':
                Log::group("Build hyperf/php: {$this->tag}");
                $cmd = "docker build '{$context}' " .
                    implode(' ', $tags) . ' ' .
                    implode(' ', $argsArgs);
                static::runCmd($cmd);
                Log::endgroup();
                Log::group("Run sanity check for {$mainTag}");
                foreach ([
                    'php -v',
                    'debuggable_php status',
                ] as $cmd) {
                    static::runCmd("docker run {$mainTag} {$cmd}");
                }
                Log::endgroup();
                break;
            case 'swoole':
            case 'swow':
                Log::group("Build {$mainTag} builder image");
                $cmd = "docker build '{$context}' " .
                    "-f '{$context}/Dockerfile.builder' " .
                    "-t '{$mainTag}-builder' " .
                    implode(' ', $argsArgs);
                static::runCmd($cmd);
                Log::endgroup();
                Log::group("Build {$mainTag} image");
                $cmd = "docker build '{$context}' " .
                    implode(' ', $tags) . ' ' .
                    implode(' ', $argsArgs);
                static::runCmd($cmd);
                Log::endgroup();
                Log::group("Build {$mainTag} debuggable image");
                $cmd = "docker build '{$context}' " .
                    "-f '{$context}/Dockerfile.debuggable' " .
                    "-t '{$mainTag}-debuggable' " .
                    implode(' ', $argsArgs);
                static::runCmd($cmd);
                Log::endgroup();
                Log::group("Run sanity check for {$mainTag}");
                foreach ([
                    "php --ri {$this->ext}",
                    $this->ext === 'swoole' ?
                        "php -r 'var_dump(\\Swoole\\Coroutine::getcid());'" :
                        "php -r 'var_dump(\\Swow\\Coroutine::getcurrent());'",
                    'composer -V',
                ] as $cmd) {
                    static::runCmd("docker run {$mainTag} {$cmd}");
                    static::runCmd("docker run {$mainTag}-debuggable {$cmd}");
                }
                Log::endgroup();
                break;
            default:
                throw new Exception('not supported target ' . $this->target);
        }
    }

    /**
     * @return Image[]
     */
    public function export(): array
    {
        $this->genAliases();
        $task = [
            'imagename' => "hyperf/{$this->target}:{$this->tag}",
            'phpver' => $this->phpver,
            'distrover' => $this->distrover,
            'distro' => $this->distro,
            'aliases' => implode(',', $this->aliases),
        ];
        if ($this->target !== 'php') {
            $task['ext'] = $this->ext;
            $task['extver'] = $this->extver;
            $task['require'] = "{$this->phpver}-{$this->distro}-{$this->distrover}";
            $task['composer'] = $this->composer;
        }

        return array_merge($task, $this->extra);
    }

    public function __toString(): string
    {
        return "Image<hyperf/{$this->target}:{$this->tag}>";
    }
}

abstract class VersionUpdater
{
    /**
     * @return array[]
     */
    abstract public static function imageArgs(): array;

    /**
     * @return array[]
     */
    abstract public function versions(bool $update = false): array;

    protected static ?string $composerVer = null;

    /**
     * @throws Exception
     */
    protected static function composerVer(): string
    {
        if (!static::$composerVer) {
            $releases = GithubAPI::req('repos/composer/composer/releases/latest');
            static::$composerVer = $releases['name'];
        }

        return static::$composerVer;
    }

    /**
     * @throws Exception
     */
    protected static function baseHash(string $baseName): string
    {
        exec("docker inspect {$baseName} -f '{{.Id}}'", $output, $ret);
        if ($ret != 0) {
            throw new Exception("failed to get {$baseName} hash");
        }

        return $output[0];
    }

    /**
     * @throws Exception
     * @return Image[]
     */
    public function list(bool $update = false): array
    {
        $imageArgs = $this->imageArgs();
        $versions = $this->versions($update);
        //var_dump($versions);
        $ret = [];

        $it = new RecursiveIteratorIterator(
            new RecursiveArrayIterator($versions),
            RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($it as $v) {
            if ($v !== []) {
                continue;
            }
            // pop out
            $ver = [];
            for ($i = $it->getDepth(); $i >= 0; $i--) {
                array_unshift($ver, $it->getSubIterator($i)->key());
            }
            $args = array_merge(
                array_combine($imageArgs['versions'], $ver),
                $imageArgs['extra'],
                [
                    'extra' => [
                        'base' => static::baseHash("{$imageArgs['extra']['distro']}:{$ver[0]}"),
                    ],
                ],
            );
            array_push($ret, new Image(...$args));
        }

        return $ret;
    }
}

class PHPAlpineUpdater extends VersionUpdater
{
    /**
     * @return array[]
     */
    public static function imageArgs(): array
    {
        return [
            'versions' => ['distrover', 'phpver'],
            'extra' => ['distro' => 'alpine'],
        ];
    }

    private ?array $versions = null;

    /**
     * @throws Exception
     * @return array[]
     */
    public function versions(bool $update = false): array
    {
        if (!$update && $this->versions) {
            return $this->versions;
        }
        $this->versions = [];
        foreach (SUPPORTED_ALPINE as $alpineVersion) {
            Log::group("Pull alpine:{$alpineVersion}");
            passthru("docker pull alpine:{$alpineVersion}", $ret);
            if ($ret !== 0) {
                throw new Exception('failed to fetch latest alpine');
            }
            Log::endgroup();
            $cmd = "docker run --rm alpine:{$alpineVersion} sh -c '" .
                //'set -x &&' .
                //'sed -i "s/dl-cdn.alpinelinux.org/mirrors.ustc.edu.cn/g" /etc/apk/repositories &&' .
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
                    1 => ['pipe', 'w'],
                    2 => STDERR,
                ],
                $pipes
            );
            $phps = '';
            Log::group("Find php versions on {$alpineVersion}");
            while (($status = proc_get_status($proc)) && $status['running']) {
                $phps .= fread($pipes[1], 4096);
                usleep(500 * 1000 /* 500ms */);
            }
            if ($status['exitcode'] !== 0) {
                throw new Exception("failed find php versions on {$alpineVersion}");
            }
            $phpPackages = explode(' ', $phps);
            foreach ($phpPackages as $phpPackage) {
                $x = explode('-', $phpPackage);
                Log::i("found PHP in {$alpineVersion}:", $x[1]);
                $this->versions[$alpineVersion][$x[1]] = [];
            }
            Log::endgroup();
        }

        return $this->versions;
    }
}

class GithubAPI
{
    /**
     * @var resource[]
     */
    private static array $contexts = [];

    /**
     * @throws Exception
     */
    public static function req(string $uri, string $method = 'GET'): array
    {
        //log::i("req", $uri);
        if (!isset(static::$contexts[$method])) {
            $headers = "accept: application/vnd.github.v3+json\r\n" .
                "user-agent: not-a-bad-script/0.1\r\n" .
                "content-type: application/json\r\n";
            $token = getenv('GITHUB_TOKEN');
            if ($token) {
                $headers .= "authorization: Bearer {$token}\r\n";
            }
            $opts = [
                'http' => [
                    'method' => $method,
                    'header' => $headers,
                ],
            ];
            static::$contexts[$method] = stream_context_create($opts);
        }
        $ret = file_get_contents("https://api.github.com/{$uri}", false, static::$contexts[$method]);
        if (!$ret) {
            throw new Exception('failed get github api');
        }

        return json_decode($ret, true);
    }
}

class SwoolePHPAlpineUpdater extends PHPAlpineUpdater
{
    /**
     * @throws Exception
     * @return array[]
     */
    public static function imageArgs(): array
    {
        return [
            'versions' => ['distrover', 'phpver', 'extver'],
            'extra' => [
                'distro' => 'alpine',
                'ext' => 'swoole',
                'composer' => static::composerVer(),
            ],
        ];
    }

    private const SWOOLE_REPO = 'swoole/swoole-src';

    private const MIN_SUPPORT_VERSION_BRANCHES = [
        'master' => '7.3',
    ];

    private const MIN_SUPPORT_VERSION = [
        '4.0.2' => '7.3',
        '4.4.2' => '7.4',
        '4.6.0' => '8.0',
    ];

    public function __construct(
        private ?array $parentVersions = null
    ) {
    }

    /**
     * @var null|array[]
     */
    private ?array $versions = null;

    /**
     * @throws Exception
     */
    public function versions(bool $update = false): array
    {
        if (!$this->parentVersions) {
            $this->parentVersions = parent::versions($update);
        }
        if ($this->versions && !$update) {
            return $this->versions;
        }
        Log::group('Find latest swoole versions');
        $tags = GithubAPI::req('repos/' . static::SWOOLE_REPO . '/git/refs/tags');
        // set(tags) => set(swoole versions)
        $latestSwooleVersions = array_fill_keys(SUPPORTED_SWOOLE, '0.0.0');
        foreach ($tags as $tag) {
            $tagName = substr($tag['ref'], 10);
            if (!preg_match('/^v(?<ver>(?<majmin>\\d+\\.\\d+)\\.\\d+)$/', $tagName, $match)) {
                continue;
            }
            foreach (SUPPORTED_SWOOLE as $swoole) {
                if ($match['majmin'] !== $swoole) {
                    continue;
                }
                if (version_compare($match['ver'], $latestSwooleVersions[$swoole], '>')) {
                    $latestSwooleVersions[$swoole] = $match['ver'];
                }
            }
        }
        //var_dump($latestSwooleVersions);
        // set(swoole versions) => map(swoole versions => max php ver)
        $maxPHPSwooleVersions = [];
        foreach ($latestSwooleVersions as $swooleVer) {
            foreach (static::MIN_SUPPORT_VERSION as $minSwoole => $phpBranch) {
                $maxPHPSwooleVersions[$swooleVer] = "{$phpBranch}.99";
                if (version_compare($minSwoole, $swooleVer, '>')) {
                    break;
                }
            }
        }
        //var_dump($maxPHPSwooleVersions);
        // set(swoole versions) => tree(alpine ver, php ver, swoole ver)
        $this->versions = $this->parentVersions;
        foreach ($this->versions as $alpine => $phps) {
            foreach ($phps as $php => $_) {
                foreach ($maxPHPSwooleVersions as $swooleVer => $maxPHPBranch) {
                    if (version_compare($php, $maxPHPBranch, '<')) {
                        $this->versions[$alpine][$php][$swooleVer] = [];
                        Log::i("enable php {$php} for {$swooleVer}");
                    }
                }
                foreach (SUPPORTED_SWOOLE_BRANCHES as $swooleBranch) {
                    $phpBranch = static::MIN_SUPPORT_VERSION_BRANCHES[$swooleBranch] ?? '8.0';
                    if (version_compare($php, $phpBranch, '>')) {
                        $this->versions[$alpine][$php][$swooleBranch] = [];
                        Log::i("enable php {$php} for {$swooleBranch}");
                    }
                }
            }
        }
        //var_dump($this->versions);
        Log::endgroup();

        return $this->versions;
    }
}

class SwowPHPAlpineUpdater extends PHPAlpineUpdater
{
    /**
     * @throws Exception
     * @return array[]
     */
    public static function imageArgs(): array
    {
        return [
            'versions' => ['distrover', 'phpver', 'extver'],
            'extra' => [
                'distro' => 'alpine',
                'ext' => 'swow',
                'composer' => static::composerVer(),
            ],
        ];
    }

    private const SWOW_REPO = 'swow/swow';

    private const MIN_SUPPORT_VERSION = '7.3';

    public function __construct(
        private ?array $parentVersions = null
    ) {
    }

    private ?array $versions = null;

    /**
     * @throws Exception
     * @return array[]
     */
    public function versions(bool $update = false): array
    {
        if (!$this->parentVersions) {
            $this->parentVersions = parent::versions($update);
        }
        if ($this->versions && !$update) {
            return $this->versions;
        }
        Log::group('Find latest swow versions');
        $tags = GithubAPI::req('repos/' . static::SWOW_REPO . '/git/refs/tags');
        // set(tags) => set(swow versions)
        $latestSwowVersions = array_fill_keys(SUPPORTED_SWOW, '0.0.0');
        foreach ($tags as $tag) {
            $tagName = substr($tag['ref'], 10);
            if (!preg_match('/^v(?<ver>(?<majmin>\\d+\\.\\d+)\\.\\d+(?:-.+)?)$/', $tagName, $match)) {
                continue;
            }
            foreach (SUPPORTED_SWOW as $swow) {
                if ($match['majmin'] !== $swow) {
                    continue;
                }
                if (version_compare($match['ver'], $latestSwowVersions[$swow], '>')) {
                    $latestSwowVersions[$swow] = $match['ver'];
                }
            }
        }
        foreach (SUPPORTED_SWOW_BRANCHES as $branch) {
            $latestSwowVersions[$branch] = $branch;
        }
        // set(swow versions) => tree(alpine ver, php ver, swoole ver)
        $this->versions = $this->parentVersions;
        foreach ($this->versions as $alpine => $phps) {
            foreach ($phps as $php => $_) {
                if (version_compare($php, static::MIN_SUPPORT_VERSION, '<')) {
                    Log::w("removing unsupported image alpine:{$alpine}");
                    unset($phps[$php]);
                    continue;
                }
                foreach ($latestSwowVersions as $swowVer) {
                    if ($swowVer === '0.0.0') {
                        continue;
                    }
                    Log::i("enable php {$php} for {$swowVer}");
                    $this->versions[$alpine][$php][$swowVer] = [];
                }
            }
        }
        Log::endgroup();

        return $this->versions;
    }
}

class CommandLine
{
    private string $argv0 = 'hyperf-docker.php';

    private string $action = 'help';

    /**
     * @var array[]
     */
    private array $output = [];

    private ?array $parentVersions = null;

    private function help(): int
    {
        $this->usage();

        return 0;
    }

    /**
     * @throws Exception
     */
    private function allImages(string $target): void
    {
        $updaterClass = [
            'php' => PHPAlpineUpdater::class,
            'swoole' => SwoolePHPAlpineUpdater::class,
            'swow' => SwowPHPAlpineUpdater::class,
        ][$target];
        /* @var PHPAlpineUpdater|SwoolePHPAlpineUpdater|SwowPHPAlpineUpdater $updater */
        $updater = new $updaterClass($this->parentVersions);
        if ($target === 'php' && !$this->parentVersions) {
            $this->parentVersions = $updater->versions();
        }
        $images = $updater->list();
        $taskSet = ($target === 'php' ? $target : 'ext');
        if ($this->action === 'list') {
            if (!isset($this->output[$taskSet])) {
                $this->output[$taskSet] = [];
            }
        }
        foreach ($images as $image) {
            $image->genAliases();
        }
        foreach ($images as $image) {
            if ($image->duplicationOf) {
                Log::i("{$image} is a duplication of {$image->duplicationOf}, skip it");
                continue;
            }
            if ($this->action === 'list') {
                $this->output[$taskSet][] = $image->export();
            } else {
                $image->build();
            }
        }
    }

    /**
     * @throws Exception
     */
    private function buildAll(
        string $target = 'all',
    ): int {
        switch ($target) {
            case 'all':
                $this->allImages('php');
                $this->allImages('swoole');
                $this->allImages('swow');
                break;
            case 'php':
                $this->allImages('php');
                break;
            case 'swoole':
                $this->allImages('php');
                $this->allImages('swoole');
                break;
            case 'swow':
                $this->allImages('php');
                $this->allImages('swow');
                break;
            default:
                Log::e("unknown target {$target}");

                return $this->usage();
        }

        return 0;
    }

    /**
     * @throws Exception
     */
    private function list(
        string $target = 'all',
        string $out = 'php://stdout',
    ): int {
        $ret = $this->buildAll($target);
        if ($ret !== 0) {
            return $ret;
        }
        file_put_contents($out, json_encode($this->output, $out === 'php://stdout' ? JSON_PRETTY_PRINT : 0));

        return 0;
    }

    private static function filterArgs(string $k, string $v): string|array|null
    {
        return match ($k) {
            'phpver', 'distro', 'distrover', 'composer', 'ext', 'extver' => $v,
            'aliases' => explode(',', $v),
            default => null,
        };
    }

    /**
     * @throws Exception
     */
    private function buildjson(string $in = 'php://stdin'): int
    {
        $json = json_decode(file_get_contents($in), true);
        $imageArgs = [];
        foreach ($json as $k => $v) {
            $o = static::filterArgs($k, $v);
            if ($o) {
                $imageArgs[$k] = $o;
            }
        }
        $image = new Image(...$imageArgs);
        $image->build();

        return 0;
    }

    /**
     * @throws Exception
     */
    private function build(...$args): int
    {
        $imageArgs = [];
        foreach ($args as $arg) {
            [$k, $v] = explode('=', $arg, 2);
            $o = static::filterArgs($k, $v);
            if ($o) {
                $imageArgs[$k] = $o;
            }
        }
        if (!isset($imageArgs['distro'])) {
            $imageArgs['distro'] = 'alpine';
        }
        if (
            !isset($imageArgs['phpver']) ||
            !isset($imageArgs['distrover'])
        ) {
            Log::e('lacking phpver or distrover');

            return $this->usage();
        }
        $image = new Image(...$imageArgs);
        $image->build();

        return 0;
    }

    private function usage(): int
    {
        echo <<<EOF
Hyperf alpine docker tool

Usage: {$this->argv0} <action> [...]

Which action can be
build: {$this->argv0} build [<option>=<value>]...
    Build an image
    Which option can be
        distro: distro name, must be "alpine"
        distrover: distro version like "egde" or "3.14"
        phpver: PHP verison like "8.0.9"
        ext: extension name, "swoole" or "swow"
        extver: Swoole/Swow version like "4.7.0"
        aliases: tag aliases applied to build image, comma splited
    For example, to build hyperf/swoole:4.7.0-php-8.0.9-alpine-3.14 with alias, use
        {$this->argv0} build phpver=8.0.9 distrover=3.14 ext=swoole extver=4.7.0 aliases=latest,4.7-php-8.0.9-alpine-3.14
    To build hyperf/php:8.0.9-alpine-3.14 with alias, use
        {$this->argv0} build phpver=8.0.9 distrover=3.14 aliases=latest,8.0-alpine-3.14
buildall: {$this->argv0} buildall [target]...
        Build all images
        target can be one of "all" "php" "swoole" "swow"
        When target is not set, "all" will be the target
        For example, to build all hyperf/{php,swow,swoole} images
            {$this->argv0} buildall
        For example, to build all hyperf/swoole images
            {$this->argv0} buildall swoole
list: {$this->argv0} list [<target> [output]]...
        List all images for ci build
        target can be one of "all" "php" "swoole" "swow"
        When target is not set, "all" will be the target
        When output is not set, results will be written to stdout
buildjson: {$this->argv0} buildjson [input]...
        Build image from json for ci build
        When input is not set, the program will read stdin for input

Environment:
    CI: set CI=true to mock github workflows environment
    GITHUB_TOKEN: github token used in api requests

License:
    remind me to fill this

EOF;

        return 1;
    }

    /**
     * @throws Exception
     */
    public function mian(array $argv): int
    {
        Log::init();
        $this->argv0 = array_shift($argv);
        $this->action = $action = array_shift($argv);
        switch ($action) {
            case 'list':
                return $this->list(...$argv);
            case 'buildall':
                return $this->buildAll(...$argv);
            case 'buildjson':
                return $this->buildjson(...$argv);
            case 'build':
                return $this->build(...$argv);
            case 'help':
                return $this->help();
            default:
                Log::e("unknown action {$action}");

                return $this->usage();
        }
    }
}

try {
    exit((new CommandLine())->mian($argv));
} catch (Exception $e) {
    Log::e('error', $e);
    exit(1);
}
