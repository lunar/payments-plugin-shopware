<?php declare(strict_types=1);

namespace Lunar\Payment\Controller;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;

use Shopware\Core\Framework\Context;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Shopware\Core\System\StateMachine\StateMachineRegistry;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Sorting\FieldSorting;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStateHandler;

use Lunar\Payment\Helpers\OrderHelper;
use Lunar\Payment\Helpers\LogHelper as Logger;

/**
 * Responsible for handling order payment transactions
 *
 * @Route(defaults={"_routeScope"={"administration"}})
 */
class OrderTransactionController extends AbstractController
{
    public function __construct(
        private EntityRepository $stateMachineHistory,
        private StateMachineRegistry $stateMachineRegistry,
        private OrderTransactionStateHandler $transactionStateHandler,
        private EntityRepository $lunarTransactionRepository,
        private OrderHelper $orderHelper,
        private SystemConfigService $systemConfigService,
        private Logger $logger
    ) {
        $this->stateMachineHistory = $stateMachineHistory;
        $this->stateMachineRegistry = $stateMachineRegistry;
        $this->transactionStateHandler = $transactionStateHandler;
        $this->lunarTransactionRepository = $lunarTransactionRepository;
        $this->orderHelper = $orderHelper;
        $this->systemConfigService = $systemConfigService;
        $this->logger = $logger;
    }

    /**
     * CAPTURE
     *
     * @Route("/api/lunar/capture", name="api.action.lunar.capture", methods={"POST"})
     */
    public function capture(Request $request, Context $context): JsonResponse
    {
        return $this->processPaymentAction($request, $context, 'capture');
    }

    /**
     * REFUND
     *
     * @Route("/api/lunar/refund", name="api.action.lunar.refund", methods={"POST"})
     */
    public function refund(Request $request, Context $context): JsonResponse
    {
        return $this->processPaymentAction($request, $context, 'refund');
    }

    /**
     * VOID / CANCEL
     *
     * @Route("/api/lunar/void", name="api.action.lunar.void", methods={"POST"})
     */
    public function void(Request $request, Context $context): JsonResponse
    {
        return $this->processPaymentAction($request, $context, 'void');
    }


    /**
     * @TODO unify code with that from \Subscriber\OrderTransactionStateChangeSubscriber.php
     *
     */
    private function processPaymentAction(
                                            Request $request,
                                            Context $context,
                                            string $actionType
    ): JsonResponse {

        switch ($actionType) {
            case OrderHelper::CAPTURE_STATUS:
                $orderTransactionAction = OrderHelper::TRANSACTION_PAID;
                break;
            case OrderHelper::REFUND_STATUS:
                $orderTransactionAction = OrderHelper::TRANSACTION_REFUND;
                break;
            case OrderHelper::VOID_STATUS:
                $orderTransactionAction = OrderHelper::TRANSACTION_VOID;
                break;
        }

        $params = $request->request->all()['params'];
        $orderId = $params['orderId'];

        try {
            $order = $this->orderHelper->getOrderById($orderId, $context);

            $lastOrderTransaction = $order->transactions->last();

            /** Change order transaction state. */
            $this->transactionStateHandler->{$orderTransactionAction}($lastOrderTransaction->id, $context);

        } catch (\Exception $e) {
            $errors[] = 'An exception occured. Please try again. If this persist please contact plugin developer.';
            $this->logger->writeLog(['EXCEPTION ' . $actionType . ' (admin): ', $e->getMessage()]);

            /** Fail order transaction. */
            $this->transactionStateHandler->fail($lastOrderTransaction->id, $context);
        }

        if (!empty($errors)) {
            return new JsonResponse([
                'status'  => empty($errors),
                'message' => 'Error',
                'code'    => 0,
                'errors'=> $errors ?? [],
            ], 400);
        }

        return new JsonResponse([
            'status'  =>  empty($errors),
            'message' => 'Success',
            'code'    => 0,
            'errors'  => $errors ?? [],
        ], 200);
    }

    /**
     * FETCH TRANSACTIONS
     *
     * @Route("/api/lunar/fetch-transactions", name="api.lunar.fetch-transactions", methods={"POST"})
     */
    public function fetchTransactions(Request $request, Context $context): JsonResponse
    {
        $errors = [];
        $orderId = $request->request->all()['params']['orderId'];

        try {
            /**
             * Check transaction registered in custom table
             */
            $criteria = new Criteria();
            $criteria->addFilter(new EqualsFilter('orderId', $orderId));
            $criteria->addSorting(new FieldSorting('createdAt', FieldSorting::ASCENDING));

            $lunarTransactions = $this->lunarTransactionRepository->search($criteria, $context);

        } catch (\Exception $e) {
            $errors[] = $e->getMessage();
        }

        if (!empty($errors)) {
            return new JsonResponse([
                'status'  =>  empty($errors),
                'message' => 'Error',
                'code'    => 0,
                'errors'  => $errors,
            ], 404);
        }

        return new JsonResponse([
            'status'  =>  empty($errors),
            'message' => 'Success',
            'code'    => 0,
            'errors'  => $errors,
            'transactions' => $lunarTransactions->getElements(),
        ], 200);
    }
}
