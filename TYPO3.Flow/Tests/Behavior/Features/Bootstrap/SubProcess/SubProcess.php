<?php
namespace TYPO3\Flow\Tests\Features\Bootstrap\SubProcess;
use TYPO3\Flow\Core\ApplicationContext;

/**
 * A wrapper for a flow sub process that allows for sending arbitrary commands to the same request
 *
 * Usage:
 *  $subProcess = new SubProcess($applicationContext);
 *  $subProcessResponse = $subProcess->execute('some:flow:command');
 */
class SubProcess {

	/**
	 * @var resource|boolean
	 */
	protected $subProcess = FALSE;

	/**
	 * @var array
	 */
	protected $pipes = array();

	/**
	 * @var ApplicationContext
	 */
	protected $context;

	/**
	 * @param ApplicationContext $context
	 */
	public function __construct(ApplicationContext $context) {
		$this->context = $context;

		$this->execute('');
		// Flush response pipe
		$this->getSubProcessResponse();
	}

	/**
	 * @param string $commandLine
	 * @return string
	 * @throws \Exception
	 */
	public function execute($commandLine) {
		if (is_resource($this->subProcess)) {
			$subProcessStatus = proc_get_status($this->subProcess);
			if ($subProcessStatus['running'] === FALSE) {
				proc_close($this->subProcess);
			}
		};
		if (!is_resource($this->subProcess)) {
			list($this->subProcess, $this->pipes) = $this->launchSubProcess();
			if ($this->subProcess === FALSE || !is_array($this->pipes)) {
				throw new \Exception('Failed launching the shell sub process');
			}
		}
		fwrite($this->pipes[0], $commandLine . "\n");
		fflush($this->pipes[0]);

		return $this->getSubProcessResponse();
	}

	/**
	 * Cleanly terminates the given sub process
	 *
	 * @return void
	 */
	public function quit() {
		fwrite($this->pipes[0], "QUIT\n");
		fclose($this->pipes[0]);
		fclose($this->pipes[1]);
		fclose($this->pipes[2]);
		proc_close($this->subProcess);
	}

	/**
	 * Launch sub process
	 *
	 * @return array The new sub process and its STDIN, STDOUT, STDERR pipes – or FALSE if an error occurred.
	 * @throws \RuntimeException
	 */
	protected function launchSubProcess() {
		$systemCommand = 'FLOW_ROOTPATH=' . escapeshellarg(FLOW_PATH_ROOT) . ' FLOW_CONTEXT=' . (string)$this->context . ' ' . PHP_BINDIR . '/php -c ' . escapeshellarg(php_ini_loaded_file()) . ' ' . escapeshellarg(FLOW_PATH_FLOW . 'Scripts/flow.php') . ' --start-slave';
		$descriptorSpecification = array(array('pipe', 'r'), array('pipe', 'w'), array('pipe', 'a'));
		$this->subProcess = proc_open($systemCommand, $descriptorSpecification, $this->pipes);
		if (!is_resource($this->subProcess)) {
			throw new \RuntimeException('Could not execute sub process.');
		}

		$read = array($this->pipes[1]);
		$write = NULL;
		$except = NULL;
		$readTimeout = 30;

		stream_select($read, $write, $except, $readTimeout);

		$subProcessStatus = proc_get_status($this->subProcess);
		return ($subProcessStatus['running'] === TRUE) ? array($this->subProcess, $this->pipes) : FALSE;
	}

	/**
	 * Returns the currently pending response from the sub process
	 *
	 * @return string
	 */
	protected function getSubProcessResponse() {
		if (!is_resource($this->subProcess)) {
			return '';
		}
		$responseLines = array();
		while (feof($this->pipes[1]) === FALSE) {
			$responseLine = fgets($this->pipes[1]);
			if ($responseLine === FALSE) {
				break;
			}
			$trimmedResponseLine = trim($responseLine);
			if ($trimmedResponseLine === 'READY') {
				break;
			}
			if ($trimmedResponseLine === '') {
				continue;
			}
			$responseLines[] = $trimmedResponseLine;
		}
		return implode("\n", $responseLines);
	}

}