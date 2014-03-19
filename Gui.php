<?php
/*
Copyright 2014 Ondrej Novy

This file is part of otodo.

otodo is free software: you can redistribute it and/or modify
it under the terms of the GNU General Public License as published by
the Free Software Foundation, either version 3 of the License, or
(at your option) any later version.

otodo is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
GNU General Public License for more details.

You should have received a copy of the GNU General Public License
along with otodo.  If not, see <http://www.gnu.org/licenses/>.
*/

class Gui {
	private $todos;
	private $filteredTodos;
	private $sort;
	private $sorts = array();
	private $message = '';
	private $lastLineNumber = null;
	private $search = null;

	public function __construct() {
		$this->load();
		$this->sorts = Config::$config['gui']['sort'];
		if (!is_array($this->sorts)) {
			$this->sorts = array($this->sorts);
		}
		$this->nextSort();
	}

	protected function load() {
		$this->todos = new TodosEx();
		$this->todos->loadFromFile(Config::$config['core']['todo_file']);
	}

	protected function save() {
		$this->todos->saveToFile(Config::$config['core']['todo_file']);
	}

	protected function backup() {
		$dir = Config::$config['core']['backup_dir'];
		if ($dir) {
			$source = Config::$config['core']['todo_file'];
			$date = date('c');
			$target =
				'todo.' .
				date('c') .
				'.txt';
			$targetA =
				$dir .
				DIRECTORY_SEPARATOR .
				$target;
			$last =
				$dir .
				DIRECTORY_SEPARATOR .
				'todo.' .
				'last' .
				'.txt';
			if (file_exists($last)) {
				$cLast = file($last);
				$cSource = file($source);
				sort($cLast);
				sort($cSource);
				if (implode("\n", $cLast) == implode("\n", $cSource)) {
					return;
				}
			}
			copy(
				$source,
				$targetA
			);
			@unlink($last);
			symlink($target, $last);
		}
	}

	protected function nextSort() {
		$sort = array_shift($this->sorts);
		array_push($this->sorts, $sort);
		$this->parseSortString($sort);
	}

	protected function parseSortString($string) {
		$sorts = explode(',', $string);
		$this->sort = array();
		foreach ($sorts as $sort) {
			$sort = trim($sort);
			if ($sort[0] == '!') {
				$asc = false;
				$sort = substr($sort, 1);
			} else {
				$asc = true;
			}
			$this->sort[$sort] = $asc;
		}
	}

	protected function parseDate($date) {
		$date = trim($date);
		$dt = false;
		foreach (Config::$config['gui']['date_format_in'] as $format) {
			$dt = DateTime::createFromFormat($format, $date);
			if ($dt !== false) {
				$dt->modify('00.00.00');
				break;
			}
		}
		if ($dt === false) {
			if ($date == '') {
				$dt = new DateTime('today');
			} elseif (preg_match('/^\+?(\d+)(d|m|w|y)?$/', $date, $matches)) {
				switch ($matches[2]) {
					case '':
					case 'd':
						$m = 'day';
					break;
					case 'm':
						$m = 'month';
					break;
					case 'w':
						$m = 'week';
					break;
					case 'y':
						$m = 'year';
					break;
					default:
						throw new DateParseException($date);
					break;
				}
				$dt = new DateTime('today');
				$dt->modify('+' . $matches[1] . ' ' . $m);
			} elseif (preg_match('/^(\d+)\.$/', $date, $matches)) {
				$dt = new DateTime(date('Y-m-' . $matches[1]));
				$dt->modify('00.00.00');
				$now = new DateTime('today');
				$i = $dt->diff($now);
				if ($i->days > 0 && $i->invert == 0) {
					$dt->modify('+1 month');
				}
			} else {
				throw new DateParseException($date);
			}
		}

		return $dt;
	}

	protected function getLineNumber($cmd) {
		$num = substr($cmd, 1);
		if (!is_numeric($num)) {
			if ($this->lastLineNumber && $num == 'l') {
				$num = $this->lastLineNumber;
			} else if (count($this->filteredTodos) == 1) {
				$num = array_pop(array_keys($this->filteredTodos));
			} else {
				$num = readline('Num: ');
			}
		}
		if (!is_numeric($num)) {
			$this->error('Need todo number');
			return null;
		}
		$num = (int) $num;
		if (!isset($this->todos[$num])) {
			$this->error('Todo ' . $num . ' doesn\'t exists');
			return null;
		}
		$this->lastLineNumber = $num;
		return $num;
	}

	protected function error($message) {
		$this->message =
			$this->config2color(Config::$config['color']['error']) .
			$message .
			$this->config2color(Config::$config['color']['default']);
	}

	protected function notice($message) {
		$this->message =
			$this->config2color(Config::$config['color']['notice']) .
			$message .
			$this->config2color(Config::$config['color']['default']);
	}

	protected function config2color($color) {
		if (!is_array($color)) {
			$color = array($color);
		}
		$out = '';
		foreach ($color as $l) {
			$out .= "\033[" . $l . "m";
		}
		return $out;
	}

	protected function readlineCompletion($input, $index) {
		$out = array();
		$search = $input;
		$prep = '';
		while (strlen($search) && in_array($search[0], array('+', '@', '/'))) {
			$prep .= $search[0];
			$search = substr($search, 1);
		}
		foreach ($this->todos as $todo) {
			foreach ($todo->projects as $project) {
				if (substr($project, 0, strlen($search)) == $search) {
					$out[] = $prep . $project;
				}
			}
			foreach ($todo->contexts as $context) {
				if (substr($context, 0, strlen($search)) == $search) {
					$out[] = $prep . $context;
				}
			}
		}
		return array_unique($out);
	}

	public function start() {
		readline_completion_function(function($input, $index) {
			return $this->readlineCompletion($input, $index);
		});
		readline_read_history(Config::$config['gui']['history_file']);

		$this->todos->sort($this->sort);
		while (true) {
			$this->save();
			$this->backup();
			readline_write_history(Config::$config['gui']['history_file']);

			$this->todos->asort($this->sort);

			$search = $this->search;
			if ($search === null) {
				$this->filteredTodos = $this->todos;
			} else {
				$this->filteredTodos = $this->todos->array_filter(function($todo) use ($search) {
					foreach ($search as $s) {
						if ($s['not'] && stripos($todo->text, $s['text']) !== false) {
							return false;
						}
					}
					foreach ($search as $s) {
						if (!$s['not'] && stripos($todo->text, $s['text']) !== false) {
							return true;
						}
					}
					foreach ($search as $s) {
						if (!$s['not']) {
							return false;
						}
					}
					return true;
				});
			}

			$textLen = 0;
			$pos = 0;
			foreach ($this->filteredTodos as $todo) {
				$pos++;
				if (strlen($todo->text) > $textLen) {
					$textLen = strlen($todo->text);
				}
				if (isset(Config::$config['gui']['max_todos']) && $pos >= Config::$config['gui']['max_todos']) {
					break;
				}
			}

			echo "\033c";
			echo str_pad('#', 3, ' ', STR_PAD_LEFT) . ' | ';
			echo 'D?| ';
			echo 'P | ';
			echo str_pad('Text', $textLen) . ' | ';
			echo str_pad('Due date', 12) . ' | ';
			echo str_pad('Recu.', 5);
			echo PHP_EOL;

			$pos = 0;
			foreach ($this->filteredTodos as $k=>$todo) {
				if ($pos++ % 2 == 0) {
					echo $this->config2color(Config::$config['color']['todo_odd']);
				} else {
					echo $this->config2color(Config::$config['color']['todo_even']);
				}
				$now = new DateTime('today');
				if (!$todo->done && $todo->due !== null) {
					$diff = $todo->due->diff($now);
					if ($diff->days == 0) {
						echo $this->config2color(Config::$config['color']['todo_due_today']);
					} else if (!$diff->invert) {
						echo $this->config2color(Config::$config['color']['todo_after_due']);
					}
				}
				if ($todo->priority !== null) {
					if (isset(Config::$config['color']['todo_prio_' . $todo->priority])) {
						echo $this->config2color(Config::$config['color']['todo_prio_' . $todo->priority]);
					} elseif (isset(Config::$config['color']['todo_prio'])) {
						echo $this->config2color(Config::$config['color']['todo_prio']);
					}
				}

				echo str_pad($k, 3, ' ', STR_PAD_LEFT) . ' | ';
				if ($todo->done) {
					echo 'X';
				} else {
					echo ' ';
				}
				echo ' | ';
				if ($todo->priority) {
					echo $todo->priority;
				} else {
					echo ' ';
				}
				echo ' | ';
				echo str_pad($todo->text, $textLen);
				echo ' | ';
				if ($todo->due) {
					echo str_pad($todo->due->format(Config::$config['gui']['date_format_out']), 12);
				} else {
					echo str_pad('', 12);
				}
				echo ' | ';
				if ($todo->recurrent) {
					echo str_pad($todo->recurrent->toString(), 5);
				} else {
					echo str_pad('', 5);
				}

				echo $this->config2color(Config::$config['color']['default']);
				echo PHP_EOL;

				if (isset(Config::$config['gui']['max_todos']) && $pos >= Config::$config['gui']['max_todos']) {
					echo '...' . PHP_EOL;
					break;
				}
			}

			echo PHP_EOL;
			echo $this->message . PHP_EOL;
			$this->message = '';

			echo 'c  Create             e  Edit' . PHP_EOL;
			echo 'r  Remove             a  Archive' . PHP_EOL;
			echo 'x  Mark as done       X  Unmark as done' . PHP_EOL;
			echo 'd  Set due date       D  Unset due date' . PHP_EOL;
			echo 'g  Set recurrent      G  Unset recurrent' . PHP_EOL;
			echo 'p  Priority           P  Unset priority' . PHP_EOL;
			echo 's  Sort: ';
			$first = true;
			foreach ($this->sort as $col => $asc) {
				if ($first) {
					$first = false;
				} else {
					echo ', ';
				}
				if (!$asc) {
					echo '!';
				}
				echo $col;
			}
			echo PHP_EOL;
			echo '/  Search';
			if ($this->search !== null) {
				echo ': ';
				$first = true;
				foreach ($this->search as $s) {
					if ($first) {
						$first = false;
					} else {
						echo ', ';
					}
					if ($s['not']) {
						echo '!';
					}
					echo $s['text'];
				}
			}
			echo PHP_EOL;
			echo 'q  Quit' . PHP_EOL;
			$cmd = readline('> ');
			$cmd = trim($cmd);
			if ($cmd === '' || $cmd === false) {
				continue;
			}
			readline_add_history($cmd);
			switch ($cmd[0]) {
				// Create new task
				case 'c':
					$text = trim(substr($cmd, 1));
					if (empty($text)) {
						$text = trim(readline('Text: '));
					}
					if ($text == '') {
						$this->error('Need text');
					} else {
						$t = new TodoEx($this->todos);
						$t->text = $text;
						$t->creationDate = new DateTime('today');
						// Detect duplicity
						$dup = $this->todos->searchSimilar($t);
						if ($dup) {
							echo 'Duplicity found: ' . $dup->text . PHP_EOL;
							$confirm = trim(readline('Really add? '));
							if ($confirm !== 'y') {
								$this->error('Todo not added');
								break;
							}
						}

						$this->todos[] = $t;
						$this->lastLineNumber = array_pop($this->todos->array_keys());
						$this->notice('Todo added');
					}
				break;

				// Edit task
				case 'e':
					$num = $this->getLineNumber($cmd);
					if ($num === null) {
						break;
					}
					readline_add_history($this->todos[$num]->text);
					$text = trim(readline('Text: '));
					if ($text === '' || $text === false) {
						$this->error('Need text');
					} else {
						$this->todos[$num]->text = $text;
					}
					$this->notice('Todo ' . $num . ' changed');
				break;

				// Remove task
				case 'r':
					$num = $this->getLineNumber($cmd);
					if ($num === null) {
						break;
					}
					$confirm = trim(readline('Do you really want to remove todo ' . $num .' (y/n)? '));
					if ($confirm === 'y') {
						unset($this->todos[$num]);
						$this->notice('Todo ' . $num . ' removed');
					} else {
						$this->error('Todo ' . $num . ' NOT removed');
					}
				break;

				// Archive
				case 'a':
					$count = $this->todos->archive();
					if ($count) {
						$this->todos->sort($this->sort);
						$this->notice($count . ' todo(s) archived');
					} else {
						$this->notice('No todos to archive');
					}
				break;

				// Mark as done
				case 'x':
					$num = $this->getLineNumber($cmd);
					if ($num === null) {
						break;
					}
					$this->todos[$num]->markDone();
					$this->notice('Todo ' . $num . ' marked done');
				break;

				// Unmark as done
				case 'X':
					$num = $this->getLineNumber($cmd);
					if ($num === null) {
						break;
					}
					$this->todos[$num]->unmarkDone();
					$this->notice('Todo ' . $num . ' unmarked done');
				break;

				// Set due date
				case 'd':
					$num = $this->getLineNumber($cmd);
					if ($num === null) {
						break;
					}
					if ($this->todos[$num]->due) {
						readline_add_history($this->todos[$num]->due->format(Config::$config['gui']['date_format_out']));
					}
					$str = trim(readline('Due date: '));
					if ($str === '' || $str === false) {
						break;
					}
					try {
						$dt = $this->parseDate($str);
						$this->todos[$num]->due = $dt;
						$this->notice('Due date set to ' . $dt->format('Y-m-d') . ' for todo ' . $num);
					} catch (DateParseException $dpe) {
						$this->error('Don\'t understand ' . $str);
					}
				break;

				// Unset due date
				case 'D':
					$num = $this->getLineNumber($cmd);
					if ($num === null) {
						break;
					}
					$this->todos[$num]->due = null;
					$this->notice('Due date unset for todo ' . $num);
				break;

				// Recurrent
				case 'g':
					$num = $this->getLineNumber($cmd);
					if ($num === null) {
						break;
					}
					if ($this->todos[$num]->recurrent) {
						readline_add_history($this->todos[$num]->recurrent->toString());
					}
					$str = trim(readline('Recurrent: '));
					if ($str === '' || $str === false) {
						break;
					}
					try {
						$r = new Recurrent($str);
						$this->todos[$num]->recurrent = $r;
						$this->notice('Todo ' . $num . ' set recurrent ' . $r->toString());
					} catch (RecurrentParseException $rpe) {
						$this->error('Don\'t understand ' . $str);
					}
				break;

				// Unset recurrent
				case 'G':
					$num = $this->getLineNumber($cmd);
					if ($num === null) {
						break;
					}
					$this->todos[$num]->recurrent = null;
					$this->notice('Todo ' . $num . ' set not recurrent');
				break;

				// Sort
				case 's':
				case 'S':
					$this->nextSort();
					$this->notice('Sorting changed');
				break;

				// Search
				case '/':
					$search = trim(substr($cmd, 1));
					if (empty($search)) {
						$search = trim(readline('Search: '));
					}
					if ($search === '') {
						$this->search = null;
					} else {
						$search = explode(',', $search);
						$this->search = array();
						foreach ($search as $w) {
							$not = false;
							if ($w[0] === '!') {
								$w = substr($w, 1);
								$not = true;
							}
							$this->search[] = array(
								'not' => $not,
								'text' => trim($w)
							);
						}
					}
				break;

				// Priority
				case 'p':
					$num = $this->getLineNumber($cmd);
					if ($num === null) {
						break;
					}
					if ($this->todos[$num]->priority) {
						readline_add_history($this->todos[$num]->priority);
					}
					$str = trim(readline('Priority: '));
					if ($str === '' || $str === false) {
						break;
					}

					if (preg_match('/^[a-zA-Z]$/', $str)) {
						$this->todos[$num]->priority = strtoupper($str);
					} else {
						$this->error('Wrong priority ' . $str);
					}
				break;

				// Unset priority
				case 'P':
					$num = $this->getLineNumber($cmd);
					if ($num === null) {
						break;
					}
					$this->todos[$num]->priority = null;
				break;

				// Quit
				case 'e':
				case 'q':
				case 'E':
				case 'Q':
					exit();
				break;
			}
		}
	}
}
