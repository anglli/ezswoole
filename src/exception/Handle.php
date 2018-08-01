<?php

namespace fashop\exception;

use Exception;
use fashop\App;
use fashop\Config;
use fashop\console\Output;
use fashop\Lang;
use fashop\Log;
use fashop\Response;
use EasySwoole\Core\Http\Request as EasySwooleRequest;
use EasySwoole\Core\Http\Response as EasySwooleResponse;

class Handle {
	protected $request ;
	/**
	 * @var \EasySwoole\Core\Http\Response
	 */
	protected $response;
	protected $render;
	protected $ignoreReport = [
		'\\fashop\\exception\\HttpException',
	];
	public function setRender($render) {
		$this->render = $render;
	}
	public function setResponse( EasySwooleResponse $response ){
		$this->response = $response;
	}
	public function setRequest(EasySwooleRequest $request){
		$this->request = $request;
	}
	/**
	 * Report or log an exception.
	 *
	 * @param  \Exception $exception
	 * @return void
	 */
	public function report(Exception $exception) {
		if (!$this->isIgnoreReport($exception)) {
			// 收集异常数据
			if (App::$debug) {
				$data = [
					'file'    => $exception->getFile(),
					'line'    => $exception->getLine(),
					'message' => $this->getMessage($exception),
					'code'    => $this->getCode($exception),
				];
				$log = "[{$data['code']}]{$data['message']}[{$data['file']}:{$data['line']}]";
			} else {
				$data = [
					'code'    => $this->getCode($exception),
					'message' => $this->getMessage($exception),
				];
				$log = "[{$data['code']}]{$data['message']}";
			}

			if (Config::get('record_trace')) {
				$log .= "\r\n" . $exception->getTraceAsString();
			}
			Log::record($log, 'error');
		}
	}

	protected function isIgnoreReport(Exception $exception) {
		foreach ($this->ignoreReport as $class) {
			if ($exception instanceof $class) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Render an exception into an HTTP response.
	 *
	 * @param  \Exception $e
	 * @return Response
	 */
	public function render(Exception $e) {
		if ($this->render && $this->render instanceof \Closure) {
			$result = call_user_func_array($this->render, [$e]);
			if ($result) {
				return $result;
			}
		}
		if ($e instanceof HttpException) {
			return $this->renderHttpException($e);
		} else {
			return $this->convertExceptionToResponse($e);
		}
	}

	/**
	 * @param Output    $output
	 * @param Exception $e
	 */
	public function renderForConsole(Output $output, Exception $e) {
		if (App::$debug) {
			$output->setVerbosity(Output::VERBOSITY_DEBUG);
		}
		$output->renderException($e);
	}

	/**
	 * @param HttpException $e
	 * @return Response
	 */
	protected function renderHttpException(HttpException $e) {
		$status   = $e->getStatusCode();
		$template = Config::get('http_exception_template');
		if (!App::$debug && !empty($template[$status])) {
			return Response::create($template[$status], 'view', $status)->assign(['e' => $e]);
		} else {
			return $this->convertExceptionToResponse($e);
		}
	}

	/**
	 * @param Exception $exception
	 * @return Response
	 */
	protected function convertExceptionToResponse(Exception $exception) {
		// 收集异常数据
		if (App::$debug) {
			// 调试模式，获取详细的错误信息
			$data = [
				'name'    => get_class($exception),
				'file'    => $exception->getFile(),
				'line'    => $exception->getLine(),
				'message' => $this->getMessage($exception),
				'trace'   => $exception->getTrace(),
				'code'    => $this->getCode($exception),
				'source'  => $this->getSourceCode($exception),
				'datas'   => $this->getExtendData($exception),
				'tables'  => [

				],
			];
		} else {
			// 部署模式仅显示 Code 和 Message
			$data = [
				'code'    => $this->getCode($exception),
				'message' => $this->getMessage($exception),
			];

			if (!Config::get('show_error_msg')) {
				// 不显示详细错误信息
				$data['message'] = Config::get('error_message');
			}
		}
		ob_start();
		extract($data);
		include Config::get('exception_tmpl');
		$content = ob_get_clean();
		if (!isset($statusCode)) {
			$statusCode = 500;
		}
		$this->response->withStatus($statusCode);
		$this->response->withAddedHeader('Content-Type','text/html;charset=utf-8');
		if(ob_get_length()){
			ob_end_clean();
		}
		$this->response->getSwooleResponse()->end($content);
		// 抛出错误为了中断
		throw $exception;

	}

	/**
	 * 获取错误编码
	 * ErrorException则使用错误级别作为错误编码
	 * @param  \Exception $exception
	 * @return integer                错误编码
	 */
	protected function getCode(Exception $exception) {
		$code = $exception->getCode();
		if (!$code && $exception instanceof ErrorException) {
			$code = $exception->getSeverity();
		}
		return $code;
	}

	/**
	 * 获取错误信息
	 * ErrorException则使用错误级别作为错误编码
	 * @param  \Exception $exception
	 * @return string                错误信息
	 */
	protected function getMessage(Exception $exception) {
		$message = $exception->getMessage();
		if (IS_CLI) {
			return $message;
		}

		if (strpos($message, ':')) {
			$name    = strstr($message, ':', true);
			$message = Lang::has($name) ? Lang::get($name) . strstr($message, ':') : $message;
		} elseif (strpos($message, ',')) {
			$name    = strstr($message, ',', true);
			$message = Lang::has($name) ? Lang::get($name) . ':' . substr(strstr($message, ','), 1) : $message;
		} elseif (Lang::has($message)) {
			$message = Lang::get($message);
		}
		return $message;
	}

	/**
	 * 获取出错文件内容
	 * 获取错误的前9行和后9行
	 * @param  \Exception $exception
	 * @return array                 错误文件内容
	 */
	protected function getSourceCode(Exception $exception) {
		// 读取前9行和后9行
		$line  = $exception->getLine();
		$first = ($line - 9 > 0) ? $line - 9 : 1;

		try {
			$contents = file($exception->getFile());
			$source   = [
				'first'  => $first,
				'source' => array_slice($contents, $first - 1, 19),
			];
		} catch (Exception $e) {
			$source = [];
		}
		return $source;
	}

	/**
	 * 获取异常扩展信息
	 * 用于非调试模式html返回类型显示
	 * @param  \Exception $exception
	 * @return array                 异常类定义的扩展数据
	 */
	protected function getExtendData(Exception $exception) {
		$data = [];
		if ($exception instanceof \fashop\Exception) {
			$data = $exception->getData();
		}
		return $data;
	}
}
