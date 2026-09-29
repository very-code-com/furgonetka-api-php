# Authentication and tokens

Furgonetka uses OAuth2. Two rules shape the library: **a refresh token is single-use and rotates**, and **there are
at most 20 sessions per application and user** (a 21st login revokes the oldest). So a token has to be stored, lock-
protected while it is refreshed, and saved before anything else; and logging in is something you do once, not per
request. `TokenManager` and the token stores do this; you choose where the token lives.

| You are | Grant | You keep | How to connect |
|---------|-------|----------|----------------|
| A shop on its own Furgonetka account | `password`, once; then refresh | the application keys in the environment; the token in a store | once, from a terminal or an admin screen: `$client->connectWithPassword($login, $password)`; the library only refreshes after that |
| An application that connects other people's accounts (SaaS, plugin, agency) | `authorization_code` | the application keys and redirect URI; one token per account in your database | a "Connect with Furgonetka.pl" button: `$client->oauth()->authorizationUrl()` (keep its `state` in the session), and in the callback `connectWithAuthorizationCode($code, $state, $expectedState)` |
| Token managed elsewhere | - | a ready access token | `FurgonetkaApiClient::create($config, new StaticTokenProvider($token))`; it is not refreshed, and a 401 is not repeated |
| Public endpoints only | none | nothing | a client without a token store |

```php
// A shop on its own account: the keys from the environment, the token in a store you choose.
$client = FurgonetkaApiClient::create(FurgonetkaApiConfig::fromEnv(), new FileTokenStore('/var/lib/shop/furgonetka.json'), logger: $logger);

// Several accounts: one client and one store per account.
$auth = $client->oauth()->authorizationUrl();          // ->url and ->state
$_SESSION['furgonetka_state'] = $auth->state;          // then redirect to $auth->url

$forShop = FurgonetkaApiClient::create($config, new PdoTokenStore($pdo, accountId: $shopId));
$forShop->connectWithAuthorizationCode($_GET['code'], $_GET['state'], $_SESSION['furgonetka_state']);
```

- Two-factor accounts: `connectWithPassword()` throws `FurgonetkaApiTwoFactorRequiredException`; ask for the code
  and call `connectWithTwoFactor($e->hint(), $login, $code)`. See [example 02](../examples/02_password_grant_and_2fa.php).
- When the token dies (revoked, or a refresh that lost a race) the next call throws
  `FurgonetkaApiReauthorizationRequiredException`: log in again. `PasswordReauthorizer` can do it by itself, from
  `FURGONETKA_USERNAME` and `FURGONETKA_PASSWORD`, if you pass it to `create()`. That keeps a password in the
  environment and cannot pass a two-factor code, so it is off by default. Never keep the password of an account that
  is not yours; use the authorization code flow there.
- Never call a login per request or per test run. Every `password` login opens one of the 20 sessions.
- A token remembers the environment and the application it was issued for; after you change either, the old token is
  ignored, and a sandbox token is never sent to production.
- The token stores: `FileTokenStore` (a 0600 file in a 0700 directory, `flock`, atomic writes) and
  `InMemoryTokenStore`. For a database, implement `TokenStoreInterface`: `load()`, `save()` and
  `synchronized(callable)`, which has to hold a lock that other processes respect while the callback runs. A sketch
  over PDO, to adapt and test against your database (the row must exist before the first refresh, or `FOR UPDATE`
  locks nothing; and keep the token encrypted at rest):

```php
final class PdoTokenStore implements TokenStoreInterface
{
    public function __construct(private \PDO $pdo, private int $accountId)
    {
    }

    public function load(): ?AccessToken
    {
        $stmt = $this->pdo->prepare('SELECT token FROM furgonetka_tokens WHERE account_id = ?');
        $stmt->execute([$this->accountId]);
        $json = $stmt->fetchColumn();

        return is_string($json) && $json !== '' ? AccessToken::fromArray(json_decode($json, true, 512, JSON_THROW_ON_ERROR)) : null;
    }

    public function save(AccessToken $token): void
    {
        $this->pdo->prepare('UPDATE furgonetka_tokens SET token = ? WHERE account_id = ?')
            ->execute([json_encode($token->toArray(), JSON_THROW_ON_ERROR), $this->accountId]);
    }

    public function synchronized(callable $callback): mixed
    {
        $this->pdo->beginTransaction();

        try {
            $this->pdo->prepare('SELECT 1 FROM furgonetka_tokens WHERE account_id = ? FOR UPDATE')->execute([$this->accountId]);
            $result = $callback();
            $this->pdo->commit();

            return $result;
        } catch (\Throwable $e) {
            $this->pdo->rollBack();

            throw $e;
        }
    }
}
```

**Laravel** (`config/services.php` and a provider):

```php
// config/services.php
'furgonetka' => ['client_id' => env('FURGONETKA_CLIENT_ID'), 'client_secret' => env('FURGONETKA_CLIENT_SECRET'), 'env' => env('FURGONETKA_ENV', 'production')],

// AppServiceProvider::register()
$this->app->singleton(FurgonetkaApiClient::class, fn ($app) => FurgonetkaApiClient::create(
    FurgonetkaApiConfig::fromArray(config('services.furgonetka')),
    new FileTokenStore(storage_path('app/furgonetka-token.json')),
    $app->make(\Psr\Log\LoggerInterface::class),
));
```

**Symfony** (`config/services.yaml`):

```yaml
services:
    VeryCodeCom\FurgonetkaApi\FurgonetkaApiConfig:
        factory: ['VeryCodeCom\FurgonetkaApi\FurgonetkaApiConfig', 'fromArray']
        arguments:
            - { client_id: '%env(FURGONETKA_CLIENT_ID)%', client_secret: '%env(FURGONETKA_CLIENT_SECRET)%', env: '%env(FURGONETKA_ENV)%' }

    VeryCodeCom\FurgonetkaApi\Auth\Store\FileTokenStore:
        arguments: ['%kernel.project_dir%/var/furgonetka-token.json']

    VeryCodeCom\FurgonetkaApi\FurgonetkaApiClient:
        factory: ['VeryCodeCom\FurgonetkaApi\FurgonetkaApiClient', 'create']
        arguments:
            $config: '@VeryCodeCom\FurgonetkaApi\FurgonetkaApiConfig'
            $tokens: '@VeryCodeCom\FurgonetkaApi\Auth\Store\FileTokenStore'
            $logger: '@logger'
```
