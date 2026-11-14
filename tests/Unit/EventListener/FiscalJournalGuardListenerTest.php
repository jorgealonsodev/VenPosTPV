<?php

declare(strict_types=1);

namespace VenPosTPV\Tests\Unit\EventListener;

use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\HttpKernel\Controller\ArgumentResolverInterface;
use Symfony\Component\HttpKernel\Controller\ControllerResolverInterface;
use Symfony\Component\HttpKernel\HttpKernel;
use Symfony\Component\HttpKernel\KernelEvents;
use VenPosTPV\EventListener\FiscalJournalGuardListener;
use VenPosTPV\Service\Fiscal\Journal\FiscalJournalBehindException;
use VenPosTPV\Service\Fiscal\Journal\FiscalJournalGuard;

/**
 * fiscal-journal S5, D7: while the database is behind the fiscal journal, every
 * endpoint that issues a document answers 423 with the POS message before its
 * controller runs (so before any number is taken and before PrestaShop is called);
 * everything else keeps working (A3).
 */
final class FiscalJournalGuardListenerTest extends TestCase
{
    private const MESSAGE = 'Caja bloqueada: la base de datos se ha restaurado desde una copia anterior a los últimos documentos. Avisa al administrador (Ajustes → VERI*FACTU → Diario fiscal).';

    /** @var list<string> controllers that ran */
    private array $ran = [];

    private function guard(bool $behind): FiscalJournalGuard
    {
        $guard = $this->createMock(FiscalJournalGuard::class);
        if ($behind) {
            $guard->method('assertCanIssue')->willThrowException(new FiscalJournalBehindException(FiscalJournalGuard::BEHIND_JOURNAL, 'A26000005'));
        }

        return $guard;
    }

    /** Runs a request through the kernel with the listener, the route already resolved to $controller. */
    private function handle(FiscalJournalGuard $guard, string $method, string $path, string $controller, bool $loggedIn = true, ?\Closure $action = null): Response
    {
        $listener = new FiscalJournalGuardListener($guard);
        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(KernelEvents::CONTROLLER, $listener->onController(...));
        $dispatcher->addListener(KernelEvents::EXCEPTION, $listener->onException(...));
        $dispatcher->addListener(KernelEvents::RESPONSE, $listener->onResponse(...));
        $action ??= function () use ($controller): Response {
            $this->ran[] = $controller;

            return new JsonResponse(['data' => 'done']);
        };
        $resolver = new class($action) implements ControllerResolverInterface {
            public function __construct(private readonly \Closure $action)
            {
            }

            public function getController(Request $request): callable|false
            {
                return $this->action;
            }
        };
        $arguments = new class implements ArgumentResolverInterface {
            public function getArguments(Request $request, callable $controller, ?\ReflectionFunctionAbstract $reflector = null): array
            {
                return [];
            }
        };

        $request = Request::create($path, $method, [], [], [], [], '{}');
        $request->attributes->set('_controller', $controller);
        $session = new Session(new MockArraySessionStorage());
        if ($loggedIn) {
            $session->set('user_id', 7);
            $session->set('role', 'cashier');
        }
        $request->setSession($session);

        return (new HttpKernel($dispatcher, $resolver, null, $arguments))->handle($request);
    }

    private function assertLocked(Response $response): void
    {
        $this->assertSame(423, $response->getStatusCode());
        $this->assertSame(['error' => self::MESSAGE, 'code' => 'fiscal_journal_behind', 'retry' => false], json_decode((string) $response->getContent(), true));
        $this->assertStringContainsString('application/json', (string) $response->headers->get('Content-Type'));
    }

    public function testEveryIssuingEndpointIsRefusedBeforeItsControllerRuns(): void
    {
        $issuing = [
            ['POST', '/api/checkout', 'VenPosTPV\Controller\Api\CheckoutController::checkout'],
            ['POST', '/api/refund', 'VenPosTPV\Controller\Api\RefundController::refund'],
            ['POST', '/api/refund/no-ticket', 'VenPosTPV\Controller\Api\RefundController::refundWithoutTicket'],
            ['POST', '/api/refund/4/retry', 'VenPosTPV\Controller\Api\RefundController::retry'],
            ['POST', '/api/exchange', 'VenPosTPV\Controller\Api\ExchangeController::exchange'],
            ['POST', '/api/exchange/4/retry', 'VenPosTPV\Controller\Api\ExchangeController::retry'],
            ['POST', '/api/invoices/from-sale/12', 'VenPosTPV\Controller\Api\InvoiceController::redeem'],
        ];
        foreach ($issuing as [$method, $path, $controller]) {
            $this->assertLocked($this->handle($this->guard(true), $method, $path, $controller));
        }
        $this->assertSame([], $this->ran, 'no issuing controller ran');
    }

    public function testTheIssuingListNamesRealControllerActions(): void
    {
        foreach (FiscalJournalGuardListener::ISSUING as $controller) {
            [$class, $method] = explode('::', $controller);
            $this->assertTrue(method_exists($class, $method), $controller . ' does not exist: the guard would not cover it');
        }
    }

    public function testEverythingElseKeepsWorkingWhileBehind(): void
    {
        $other = [
            ['GET', '/api/fiscal/journal', 'VenPosTPV\Controller\Api\FiscalJournalController::status'],
            ['POST', '/api/checkout/coupon-preview', 'VenPosTPV\Controller\Api\CheckoutController::couponPreview'],
            ['GET', '/api/invoices/from-sale/12/preview', 'VenPosTPV\Controller\Api\InvoiceController::preview'],
            ['POST', '/api/exchange/4/voucher', 'VenPosTPV\Controller\Api\ExchangeController::closeWithVoucher'],
            ['GET', '/api/refund/4/pdf', 'VenPosTPV\Controller\Api\RefundController::creditNotePdf'],
        ];
        foreach ($other as [$method, $path, $controller]) {
            $this->assertSame(200, $this->handle($this->guard(true), $method, $path, $controller)->getStatusCode(), $path);
        }
        $this->assertSame(array_column($other, 2), $this->ran);
    }

    public function testAnUpToDateDatabaseIssuesNormally(): void
    {
        $guard = $this->createMock(FiscalJournalGuard::class);
        $guard->expects($this->once())->method('assertCanIssue');

        $response = $this->handle($guard, 'POST', '/api/checkout', 'VenPosTPV\Controller\Api\CheckoutController::checkout');

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(['VenPosTPV\Controller\Api\CheckoutController::checkout'], $this->ran);
    }

    public function testARequestWithoutASessionUserIsLeftToTheControllersOwnCheck(): void
    {
        $guard = $this->createMock(FiscalJournalGuard::class);
        $guard->expects($this->never())->method('assertCanIssue');

        $this->assertSame(200, $this->handle($guard, 'POST', '/api/checkout', 'VenPosTPV\Controller\Api\CheckoutController::checkout', false)->getStatusCode());
    }

    public function testTheRefusalThrownDeeperInTheRequestIsAlsoA423(): void
    {
        $guard = $this->createMock(FiscalJournalGuard::class);
        $action = static function (): Response {
            throw new \RuntimeException('issue failed', 0, new FiscalJournalBehindException(FiscalJournalGuard::BEHIND_JOURNAL, 'A26000005'));
        };

        $this->assertLocked($this->handle($guard, 'POST', '/api/checkout', 'VenPosTPV\Controller\Api\CheckoutController::checkout', true, $action));
    }

    /**
     * The in-lock refusal (FiscalRecorder, D5) is caught by the services and the
     * controllers' own catch blocks: the error answer they build is turned into the 423.
     */
    public function testARefusalCaughtDeeperTurnsTheErrorAnswerIntoA423(): void
    {
        foreach ([500, 502, 409] as $status) {
            $guard = $this->createMock(FiscalJournalGuard::class);
            $guard->method('takeRefusal')->willReturn(true);
            $action = static fn (): Response => new JsonResponse(['error' => 'Internal server error', 'retry' => true], $status);

            $this->assertLocked($this->handle($guard, 'POST', '/api/refund', 'VenPosTPV\Controller\Api\RefundController::refund', true, $action));
        }
    }

    public function testASuccessfulAnswerOrAnErrorWithoutRefusalIsLeftAlone(): void
    {
        $guard = $this->createMock(FiscalJournalGuard::class);
        $guard->method('takeRefusal')->willReturn(true);
        $this->assertSame(200, $this->handle($guard, 'POST', '/api/exchange', 'VenPosTPV\Controller\Api\ExchangeController::exchange')->getStatusCode(), 'part of an exchange was done: its own answer says so');

        $guard = $this->createMock(FiscalJournalGuard::class);
        $guard->method('takeRefusal')->willReturn(false);
        $action = static fn (): Response => new JsonResponse(['error' => 'Internal server error'], 500);
        $this->assertSame(500, $this->handle($guard, 'POST', '/api/checkout', 'VenPosTPV\Controller\Api\CheckoutController::checkout', true, $action)->getStatusCode());
    }
}
