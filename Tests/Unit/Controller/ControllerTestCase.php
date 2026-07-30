<?php

declare(strict_types=1);

namespace MauticPlugin\CustomObjectsBundle\Tests\Unit\Controller;

use Doctrine\Persistence\ManagerRegistry;
use Mautic\CoreBundle\Factory\ModelFactory;
use Mautic\CoreBundle\Helper\CoreParametersHelper;
use Mautic\CoreBundle\Helper\UserHelper;
use Mautic\CoreBundle\Model\NotificationModel;
use Mautic\CoreBundle\Security\Permissions\CorePermissions;
use Mautic\CoreBundle\Service\FlashBag;
use Mautic\CoreBundle\Translation\Translator;
use PHPUnit\Framework\MockObject\MockObject;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\HeaderBag;
use Symfony\Component\HttpFoundation\ParameterBag;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\Form\SubmitButton;
use Symfony\Component\HttpKernel\HttpKernel;
use Symfony\Component\Routing\Router;
use Symfony\Component\Routing\RouterInterface;
use Twig\Environment;

/**
 * Even though we use nice controllers with defined dependencies, when we call some method like
 * `forward` or `postActionRedirect` it use container and all that nasty stuff. This trait should
 * take care of it.
 */
class ControllerTestCase extends \PHPUnit\Framework\TestCase
{
    protected $request;
    protected $session;

    /**
     * @var Router
     */
    protected $router;

    /**
     * @var ContainerInterface
     */
    protected $container;

    /**
     * @var MockObject|UserHelper
     */
    protected $userHelper;

    /**
     * @var MockObject|ManagerRegistry
     */
    protected $managerRegistry;

    /**
     * @var MockObject|ModelFactory
     */
    protected $modelFactory;

    /**
     * @var MockObject|CoreParametersHelper
     */
    protected $coreParametersHelper;

    /**
     * @var MockObject|EventDispatcherInterface
     */
    protected $dispatcher;

    /**
     * @var MockObject|Translator
     */
    protected $translator;

    /**
     * @var MockObject|FlashBag
     */
    protected $flashBag;

    /**
     * @var MockObject|RequestStack
     */
    protected $requestStack;

    /**
     * @var MockObject|CorePermissions
     */
    protected $security;

    protected function setUp(): void
    {
        parent::setUp();

        $this->security             = $this->createMock(CorePermissions::class);
        $this->userHelper           = $this->createMock(UserHelper::class);
        $this->container            = $this->createMock(ContainerInterface::class);
        $this->router               = $this->createMock(RouterInterface::class);
        $this->managerRegistry      = $this->createMock(ManagerRegistry::class);
        $this->modelFactory         = $this->createMock(ModelFactory::class);
        $this->coreParametersHelper = $this->createMock(CoreParametersHelper::class);
        $this->dispatcher           = $this->createMock(EventDispatcherInterface::class);
        $this->translator           = $this->createMock(Translator::class);
        $this->flashBag             = $this->createMock(FlashBag::class);
        $this->requestStack         = $this->createMock(RequestStack::class);
        $this->security             = $this->createMock(CorePermissions::class);
    }

    protected function addSymfonyDependencies(AbstractController $controller): void
    {
        $requestStack = empty($this->requestStack) ? $this->createMock(RequestStack::class) : $this->requestStack;
        $request      = empty($this->request) ? $this->createMock(Request::class) : $this->request;
        $session      = empty($this->session) ? $this->createMock(Session::class) : $this->session;
        $modelFactory = empty($this->modelFactory) ? $this->createMock(ModelFactory::class) : $this->modelFactory;

        $httpKernel        = $this->createMock(HttpKernel::class);
        $response          = $this->createMock(Response::class);
        $twig              = $this->createMock(Environment::class);
        $notificationModel = $this->createMock(NotificationModel::class);

        $this->container->method('get')->willReturnMap([
            ['request_stack', ContainerInterface::EXCEPTION_ON_INVALID_REFERENCE, $requestStack],
            ['http_kernel', ContainerInterface::EXCEPTION_ON_INVALID_REFERENCE, $httpKernel],
            ['mautic.model.factory', ContainerInterface::EXCEPTION_ON_INVALID_REFERENCE, $modelFactory],
            ['session', ContainerInterface::EXCEPTION_ON_INVALID_REFERENCE, $session],
            ['mautic.security', ContainerInterface::EXCEPTION_ON_INVALID_REFERENCE, $this->security],
            ['router', ContainerInterface::EXCEPTION_ON_INVALID_REFERENCE, $this->router],
            ['mautic.helper.user', ContainerInterface::EXCEPTION_ON_INVALID_REFERENCE, $this->userHelper],
            ['twig', ContainerInterface::EXCEPTION_ON_INVALID_REFERENCE, $twig],
        ]);

        $this->container->method('has')->willReturnMap([
            ['twig', true],
        ]);

        $modelFactory->method('getModel')->willReturnMap([
            ['core.notification', $notificationModel],
        ]);

        $request->query   = new ParameterBag();
        $request->headers = new HeaderBag();

        if (method_exists($request, 'method')) { // This is terrible, we should not mock Request at all. Damn you old me!
            $request->method('duplicate')->willReturnSelf();
        }

        $httpKernel->method('handle')->willReturn($response);
        $notificationModel->method('getNotificationContent')->willReturn([[], '', '']);
        $requestStack->method('getCurrentRequest')->willReturn($request);

        $controller->setContainer($this->container);
    }

    /**
     * Returns a mock double for the form button field returned by
     * `$form->get('buttons')->get('save')` (and similar "clicked" checks).
     *
     * At runtime, Symfony resolves that field to an instance of
     * {@see \Symfony\Component\Form\SubmitButton}, which implements both
     * FormInterface::class (form children are themselves forms) and
     * ClickableInterface::class (for isClicked()). PHPUnit enforces the
     * FormInterface::get(): FormInterface return type declaration, so a
     * mock built from ClickableInterface alone is rejected. Mocking the
     * concrete SubmitButton class satisfies both interfaces at once.
     *
     * @return MockObject|SubmitButton
     */
    protected function createClickableFormMock(): MockObject
    {
        return $this->createMock(SubmitButton::class);
    }
}
