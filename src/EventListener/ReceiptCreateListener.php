<?php

namespace Message\Mothership\Voucher\EventListener;

use Message\Mothership\Epos\Branch;
use Message\Mothership\Epos\Receipt;

use Message\Mothership\Commerce\Refund\Refund as BaseRefund;
use Message\Mothership\Commerce\Order\Transaction;
use Message\Mothership\Commerce\Order\Order;
use Message\Mothership\Commerce\Order\Entity\Payment\Payment;
use Message\Mothership\Commerce\Order\Entity\Refund\Refund as OrderRefund;
use Message\Mothership\Commerce\Order\Entity\Item\Item;

use Message\Cog\Event\EventListener as BaseListener;
use Message\Cog\Event\SubscriberInterface;

/**
 * Event listeners for creating voucher receipts for transactions.
 *
 * @author Joe Holdcroft <joe@message.co.uk>
 */
class ReceiptCreateListener extends BaseListener implements SubscriberInterface
{
	/**
	 * {@inheritDoc}
	 */
	static public function getSubscribedEvents()
	{
		return array(
			Transaction\Events::CREATE_COMPLETE => array(
				array('createVoucherUsageReceipt'),
				array('createVoucherGeneratedReceiptForVoucherPurchases'),
				array('createVoucherGeneratedReceiptForVoucherRefunds'),
			),
		);
	}

	/**
	 * Create a "receipt usage" receipt for orders where a voucher has been used
	 * as a payment method
	 *
	 * This event listens to the "create complete" event because we need the
	 * order to have already been created in the database for the receipt so
	 * we can get the order's ID and show it on the receipt.
	 *
	 * @param Transaction\Event $event
	 */
	public function createVoucherUsageReceipt(Transaction\Event\Event $event)
	{
		$transaction = $event->getTransaction();

		// Skip if the transaction is not of type "new order"
		if (Transaction\Types::ORDER !== $transaction->type) {
			return false;
		}

		$receiptCreate   = $this->get('order.receipt.create');
		$transactionEdit = $this->get('order.transaction.edit');
		$template        = $this->get('receipt.templates')->get('voucher_usage');
		$factory         = $this->get('receipt.factory');

		$orders = $transaction->records->getByType(Order::RECORD_TYPE);
		$order = array_shift($orders);

		// Skip if the order was not placed on EPOS
		if ('epos' !== $order->type) {
			return false;
		}

		foreach ($transaction->records->getByType(Payment::RECORD_TYPE) as $payment) {
			if ('voucher' !== $payment->method->getName()) {
				continue;
			}

			$template->setTransaction($transaction);
			$template->setVoucherPayment($payment);

			$receipts = $factory->build($template);

			foreach ($receipts as $receipt) {
				$orderReceipt = new Receipt\OrderEntity\Receipt($receipt);
				$orderReceipt->order = $order;

				$receiptCreate->create($orderReceipt);

				// Add the order receipt to the transaction
				$transaction->records->add($orderReceipt);
			}
		}

		// Save the updated transaction
		$transactionEdit->save($transaction);
	}

	/**
	 * Create a "voucher generated" receipt when a voucher is purchased on EPOS.
	 *
	 * This event listens to the "create complete" event because we need the
	 * order to have already been created in the database for the receipt so
	 * we can get the order's ID and show it on the receipt.
	 *
	 * @param Transaction\Event $event
	 */
	public function createVoucherGeneratedReceiptForVoucherPurchases(Transaction\Event\Event $event)
	{
		$transaction = $event->getTransaction();

		// Skip if the transaction is not of type "new order"
		if (Transaction\Types::ORDER !== $transaction->type) {
			return false;
		}

		$receiptCreate   = $this->get('order.receipt.create');
		$transactionEdit = $this->get('order.transaction.edit');
		$voucherLoader   = $this->get('voucher.loader');
		$template        = $this->get('receipt.templates')->get('voucher_generated');
		$factory         = $this->get('receipt.factory');

		$orders = $transaction->records->getByType(Order::RECORD_TYPE);
		$order = array_shift($orders);

		// Skip if the order was not placed on EPOS
		if ('epos' !== $order->type) {
			return false;
		}

		foreach ($transaction->records->getByType(Item::RECORD_TYPE) as $item) {
			if (!$item->personalisation->exists('voucher_id')) {
				continue;
			}

			$receipts = $this->_createVoucherGeneratedReceipts($item->personalisation->get('voucher_id'));

			$voucherID = $item->personalisation->get('voucher_id');
			$voucher   = $voucherLoader->getByID($voucherID);

			$template->setVoucher($voucher);

			$receipts = $factory->build($template);

			foreach ($receipts as $receipt) {
				$orderReceipt = new Receipt\OrderEntity\Receipt($receipt);
				$orderReceipt->order = $order;

				$receiptCreate->create($orderReceipt);

				// Add the order receipt to the transaction
				$transaction->records->add($orderReceipt);
			}
		}

		// Save the updated transaction
		$transactionEdit->save($transaction);
	}

	/**
	 * Create a "voucher generated" receipt when a refund is made to the
	 * "voucher" method.
	 *
	 * This event listens to the "create complete" event because we need the
	 * order to have already been created in the database for the receipt so
	 * we can get the order's ID and show it on the receipt.
	 *
	 * @param Transaction\Event $event
	 */
	public function createVoucherGeneratedReceiptForVoucherRefunds(Transaction\Event\Event $event)
	{
		$transaction = $event->getTransaction();

		$receiptCreate      = $this->get('receipt.create');
		$orderReceiptCreate = $this->get('receipt.create');
		$transactionEdit    = $this->get('order.transaction.edit');
		$voucherLoader      = $this->get('voucher.loader');
		$template           = $this->get('receipt.templates')->get('voucher_generated');
		$factory            = $this->get('receipt.factory');

		$records = array_merge(
			$transaction->records->getByType(BaseRefund::RECORD_TYPE),
			$transaction->records->getByType(OrderRefund::RECORD_TYPE)
		);

		foreach ($records as $refund) {
			if ('voucher' !== $refund->method->getName()
			 || is_null($refund->reference)) {
				continue;
			}

			$voucher = $voucherLoader->getByID($refund->reference);

			$template->setVoucher($voucher);

			$receipts = $factory->build($template);

			foreach ($receipts as $receipt) {
				if ($refund instanceof OrderRefund) {
					$receipt = new Receipt\OrderEntity\Receipt($receipt);
					$receipt->order = $refund->order;

					$orderReceiptCreate->create($receipt);
				}
				else {
					$receiptCreate->create($receipt);
				}

				$transaction->records->add($receipt);
			}
		}

		// Save the updated transaction
		$transactionEdit->save($transaction);
	}
}