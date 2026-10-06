<?php

declare(strict_types=1);

use Marko\Session\Config\SessionConfig;
use Marko\Session\Contracts\SessionHandlerInterface;
use Marko\Session\Exceptions\InvalidSessionIdException;
use Marko\Session\Exceptions\SessionNotStartedException;
use Marko\Session\Session;
use Marko\Testing\Fake\FakeConfigRepository;

/**
 * Builds the SessionConfig shared by the tests in this file.
 */
function createTestSessionConfig(): SessionConfig
{
    return new SessionConfig(new FakeConfigRepository([
        'session.driver' => 'array',
        'session.lifetime' => 120,
        'session.expire_on_close' => false,
        'session.path' => '/tmp',
        'session.cookie.name' => 'PHPSESSID',
        'session.cookie.path' => '/',
        'session.cookie.domain' => '',
        'session.cookie.secure' => false,
        'session.cookie.httponly' => true,
        'session.cookie.samesite' => 'lax',
        'session.gc_probability' => 1,
        'session.gc_divisor' => 100,
    ]));
}

/**
 * An in-memory SessionHandlerInterface implementation used to drive a real
 * Session without touching the filesystem.
 */
function createInMemorySessionHandler(): SessionHandlerInterface
{
    return new class () implements SessionHandlerInterface
    {
        /** @var array<string, string> */
        public array $written = [];

        /** @var array<int, string> */
        public array $calls = [];

        public function open(
            string $path,
            string $name,
        ): bool {
            return true;
        }

        public function close(): bool
        {
            return true;
        }

        public function read(string $id): string|false
        {
            return $this->written[$id] ?? '';
        }

        public function write(
            string $id,
            string $data,
        ): bool {
            $this->calls[] = 'write';
            $this->written[$id] = $data;

            return true;
        }

        public function destroy(string $id): bool
        {
            unset($this->written[$id]);

            return true;
        }

        public function gc(int $max_lifetime): int|false
        {
            return 0;
        }

        public function validateId(string $id): bool
        {
            return isset($this->written[$id]);
        }

        public function updateTimestamp(
            string $id,
            string $data,
        ): bool {
            $this->calls[] = 'updateTimestamp';

            return isset($this->written[$id]);
        }
    };
}

/**
 * Creates a Session with started=true via reflection so PHP session functions
 * are not required to drive the write-after-close behaviour.
 */
function createStartedSession(): Session
{
    $sessionConfig = createTestSessionConfig();
    $session = new Session(createInMemorySessionHandler(), $sessionConfig);

    // Use reflection to set started = true without triggering session_start()
    $reflection = new ReflectionProperty(Session::class, 'started');
    $reflection->setValue($session, true);

    return $session;
}

it('throws a loud session exception when set is called after save', function (): void {
    $session = createStartedSession();

    $session->save();

    expect(fn () => $session->set('key', 'value'))
        ->toThrow(SessionNotStartedException::class);
});

it('persists data written before save', function (): void {
    $session = createStartedSession();

    $session->set('key', 'value');

    expect($session->get('key'))->toBe('value');
});

it('disables sapi cookie emission when the session starts', function (): void {
    $session = new Session(createInMemorySessionHandler(), createTestSessionConfig());

    $session->start();

    try {
        expect(ini_get('session.use_cookies'))->toBe('0');
    } finally {
        $session->save();
    }
});

it('clears the session id when reset', function (): void {
    $session = createStartedSession();
    $idProperty = new ReflectionProperty(Session::class, 'id');
    $idProperty->setValue($session, 'abcdefghijklmnopqrstuvwxyz012345');

    $session->reset();

    expect($session->getId())->toBe('');
});

it('clears the session data when reset', function (): void {
    $session = createStartedSession();
    $session->set('key', 'value');

    $session->reset();

    $dataProperty = new ReflectionProperty(Session::class, 'data');

    expect($dataProperty->getValue($session))->toBeEmpty();
});

it('starts a fresh session when a second request arrives with no cookie', function (): void {
    $session = new Session(createInMemorySessionHandler(), createTestSessionConfig());

    // Request 1: an authenticated visitor
    $session->start();
    $firstRequestId = $session->getId();
    $session->set('user_id', 42);
    $session->save();

    // Worker resets state between requests
    $session->reset();

    // Request 2: an anonymous visitor with no session cookie — nothing calls setId()
    $session->start();

    try {
        expect($session->getId())->not->toBe($firstRequestId)
            ->and($session->get('user_id'))->toBeNull();
    } finally {
        $session->save();
    }
});

it('resumes the same session when a second request arrives with the same cookie', function (): void {
    $session = new Session(createInMemorySessionHandler(), createTestSessionConfig());

    // Request 1: a visitor sets data and receives a session cookie
    $session->start();
    $firstRequestId = $session->getId();
    $session->set('user_id', 42);
    $session->save();

    // Worker resets state between requests
    $session->reset();

    // Request 2: the same visitor returns with the session cookie from request 1
    $session->setId($firstRequestId);
    $session->start();

    try {
        expect($session->getId())->toBe($firstRequestId)
            ->and($session->get('user_id'))->toBe(42);
    } finally {
        $session->save();
    }
});

describe('modification tracking', function (): void {
    it('reports an untouched started session as not modified', function (): void {
        $session = new Session(createInMemorySessionHandler(), createTestSessionConfig());
        $session->start();

        try {
            expect($session->isModified())->toBeFalse();
        } finally {
            $session->discard();
        }
    });

    it('reports a session that was only read as not modified', function (): void {
        $session = new Session(createInMemorySessionHandler(), createTestSessionConfig());
        $session->start();

        try {
            $session->get('user_id');
            $session->has('user_id');
            $session->flash()->peek('success');

            expect($session->isModified())->toBeFalse();
        } finally {
            $session->discard();
        }
    });

    it('reports the session modified after a value is set', function (): void {
        $session = new Session(createInMemorySessionHandler(), createTestSessionConfig());
        $session->start();

        try {
            $session->set('key', 'value');

            expect($session->isModified())->toBeTrue();
        } finally {
            $session->discard();
        }
    });

    it('reports the session modified after a flash message is added', function (): void {
        $session = new Session(createInMemorySessionHandler(), createTestSessionConfig());
        $session->start();

        try {
            $session->flash()->add('success', 'Saved');

            expect($session->isModified())->toBeTrue();
        } finally {
            $session->discard();
        }
    });

    it('reports the session modified after the id is regenerated', function (): void {
        $session = new Session(createInMemorySessionHandler(), createTestSessionConfig());
        $session->start();

        try {
            $session->regenerate();

            expect($session->isModified())->toBeTrue();
        } finally {
            $session->discard();
        }
    });

    it('reports a session that is not started as not modified', function (): void {
        $session = new Session(createInMemorySessionHandler(), createTestSessionConfig());

        expect($session->isModified())->toBeFalse();
    });
});

describe('discard', function (): void {
    it('does not write to the handler when the session is discarded', function (): void {
        $handler = createInMemorySessionHandler();
        $session = new Session($handler, createTestSessionConfig());
        $session->start();
        $session->set('key', 'value');

        $session->discard();

        expect($handler->written)->toBeEmpty()
            ->and($session->started)->toBeFalse();
    });

    it('writes to the handler when the session is saved', function (): void {
        $handler = createInMemorySessionHandler();
        $session = new Session($handler, createTestSessionConfig());
        $session->start();
        $session->set('key', 'value');

        $session->save();

        expect($handler->written)->toHaveKey($session->getId());
    });

    it('can start a fresh session after a discard in the same process', function (): void {
        $session = new Session(createInMemorySessionHandler(), createTestSessionConfig());
        $session->start();
        $session->discard();
        $session->reset();

        $session->start();

        try {
            expect($session->started)->toBeTrue();
        } finally {
            $session->discard();
        }
    });

    it('does nothing when discarding a session that is not started', function (): void {
        $session = new Session(createInMemorySessionHandler(), createTestSessionConfig());

        $session->discard();

        expect($session->started)->toBeFalse();
    });
});

describe('strict session ids', function (): void {
    it('enables lazy writes so unchanged sessions are not rewritten', function (): void {
        $previous = ini_get('session.lazy_write');
        ini_set('session.lazy_write', '0');
        $session = new Session(createInMemorySessionHandler(), createTestSessionConfig());

        try {
            $session->start();

            expect(ini_get('session.lazy_write'))->toBe('1');
        } finally {
            $session->discard();
            ini_set('session.lazy_write', (string) $previous);
        }
    });

    it('requires session handlers to validate ids and update timestamps', function (): void {
        expect(is_subclass_of(SessionHandlerInterface::class, SessionUpdateTimestampHandlerInterface::class))
            ->toBeTrue();
    });

    it('discards a well-formed session id the handler does not know and starts with a fresh id', function (): void {
        $handler = createInMemorySessionHandler();
        $session = new Session($handler, createTestSessionConfig());
        $unknownId = str_repeat('a', 40);

        $session->setId($unknownId);
        $session->start();

        try {
            expect($session->getId())->not->toBe($unknownId)
                ->and($session->getId())->not->toBeEmpty()
                ->and($session->isModified())->toBeFalse()
                ->and($handler->written)->not->toHaveKey($unknownId);
        } finally {
            $session->discard();
        }
    });

    it('resumes a session id the handler knows', function (): void {
        $handler = createInMemorySessionHandler();
        $knownId = str_repeat('b', 40);
        $handler->written[$knownId] = 'user_id|i:42;';
        $session = new Session($handler, createTestSessionConfig());

        $session->setId($knownId);
        $session->start();

        try {
            expect($session->getId())->toBe($knownId)
                ->and($session->get('user_id'))->toBe(42);
        } finally {
            $session->discard();
        }
    });

    it(
        'refreshes the timestamp instead of rewriting the payload when a resumed session is saved unchanged',
        function (): void {
            $handler = createInMemorySessionHandler();
            $knownId = str_repeat('c', 40);
            $handler->written[$knownId] = 'user_id|i:42;';
            $session = new Session($handler, createTestSessionConfig());

            $session->setId($knownId);
            $session->start();
            $session->get('user_id');
            $session->save();

            expect($handler->calls)->toBe(['updateTimestamp'])
                ->and($handler->written[$knownId])->toBe('user_id|i:42;');
        },
    );

    it(
        'writes the payload under the new id when a resumed session is regenerated without other changes',
        function (): void {
            $handler = createInMemorySessionHandler();
            $knownId = str_repeat('d', 40);
            $handler->written[$knownId] = 'user_id|i:42;';
            $session = new Session($handler, createTestSessionConfig());

            $session->setId($knownId);
            $session->start();
            $session->regenerate();
            $newId = $session->getId();
            $session->save();

            expect($newId)->not->toBe($knownId)
                ->and($handler->written)->not->toHaveKey($knownId)
                ->and($handler->written[$newId] ?? null)->toBe('user_id|i:42;');
        },
    );

    it('does not include the rejected session id in the invalid session id exception', function (): void {
        $session = new Session(createInMemorySessionHandler(), createTestSessionConfig());
        $tamperedId = 'attacker<script>chosen';

        $thrown = catchInvalidSessionId(fn () => $session->setId($tamperedId));

        expect($thrown)->toBeInstanceOf(InvalidSessionIdException::class)
            ->and($thrown->getMessage())->not->toContain($tamperedId)
            ->and($thrown->getContext())->not->toContain($tamperedId);
    });
});

function catchInvalidSessionId(Closure $callback): ?InvalidSessionIdException
{
    try {
        $callback();
    } catch (InvalidSessionIdException $exception) {
        return $exception;
    }

    return null;
}
