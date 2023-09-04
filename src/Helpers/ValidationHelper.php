<?php declare(strict_types=1);

namespace Lunar\Payment\Helpers;

use Lunar\Payment\lib\Exception\ApiException;

/**
 * Returns descriptive error messages
 */
class ValidationHelper
{
	/**
	 * @param ApiException $exception
	 * @param string $context
	 *
	 * @return string
	 */
	public static function handleExceptions( ApiException $exception, $context = '' ) {
		if ( ! $exception ) {
			return false;
		}

		$exceptionCode = $exception->getHttpStatus();
		$message = '';

		switch ( $exceptionCode ) {

			case 400: //'InvalidRequest':
				$message = "The request is not valid! Check if there is any validation bellow this message and adjust if possible, if not, and the problem persists, contact the developer.";
				break;

			case 401: //'Unauthorized':
				$message = "The operation is not properly authorized! Check the credentials set in settings for Lunar plugin.";
				break;

			case 403: //'Forbidden':
				$message = "The operation is not allowed! You do not have the rights to perform the operation, make sure you have all the grants required on your Lunar account.";
				break;

			case 404: //'NotFound':
				$message = "Transaction not found! Check the transaction key used for the operation.";
				break;

			case 409: //'Conflict':
				$message = "The operation leads to a conflict! The same transaction is being requested for modification at the same time. Try again later.";
				break;

			case 500: //'ApiConnection':
				$message = "Network issues ! Check your connection and try again.";
				break;

			default: //'ApiException':
				$message = "There has been a server issue! If this problem persists contact the developer.";
				break;
		}

		$message = 'Api Error: ' . $message;

		if ( $context ) {
			$message = $context . PHP_EOL . $message;
		}

		return $message;
	}
}