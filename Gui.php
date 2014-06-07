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
	private $readLine = null;
	private $columns = array(
		'num' => array(
			'title' => '#',
			'padTitle' => STR_PAD_BOTH,
			'padValue' => STR_PAD_LEFT
		),
		'done' => array(
			'title' => 'D',
			'padTitle' => STR_PAD_RIGHT,
			'padValue' => STR_PAD_RIGHT
		),
		'doneDate' => array(
			'title' => 'Done date',
			'padTitle' => STR_PAD_RIGHT,
			'padValue' => STR_PAD_RIGHT
		),
		'priority' => array(
			'title' => 'P',
			'padTitle' => STR_PAD_RIGHT,
			'padValue' => STR_PAD_RIGHT
		),
		'creationDate' => array(
			'title' => 'Created',
			'padTitle' => STR_PAD_RIGHT,
			'padValue' => STR_PAD_RIGHT
		),
		'text' => array(
			'title' => 'Text',
			'padTitle' => STR_PAD_RIGHT,
			'padValue' => STR_PAD_RIGHT
		),
		'due' => array(
			'title' => 'Due date',
			'padTitle' => STR_PAD_RIGHT,
			'padValue' => STR_PAD_RIGHT
		),
		'recurrent' => array(
			'title' => 'Recu.',
			'padTitle' => STR_PAD_RIGHT,
			'padValue' => STR_PAD_RIGHT
		),
		'projects' => array(
			'title' => 'Projects',
			'padTitle' => STR_PAD_RIGHT,
			'padValue' => STR_PAD_RIGHT
		),
		'contexts' => array(
			'title' => 'Contexts',
			'padTitle' => STR_PAD_RIGHT,
			'padValue' => STR_PAD_RIGHT
		),
	);

	public function __construct() {
		$this->load();
		$this->sorts = Config::$config['gui']['sort'];
		if (!is_array($this->sorts)) {
			$this->sorts = array($this->sorts);
		}
		$this->nextSort();
	}

	protected function load() {
		try {
			$this->todos = new TodosEx();
			$this->todos->loadFromFile(Config::$config['core']['todo_file']);
		} catch (TodosLoadException $tle) {
			// File doesn't exists or isn't readable,
			// ignore it, but inform user
			$this->error($tle->getMessage());
			// Create empty todo file
			touch(Config::$config['core']['todo_file']);
		}
	}

	protected function save() {
		$this->todos->saveToFile(Config::$config['core']['todo_file']);
		$this->backup();
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
				if (!isset($matches[2])) {
					$matches[2] = 'd';
				}
				switch ($matches[2]) {
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
				$num = $this->readLine->read('Num: ');
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

	protected function readlineCompletion($search) {
		$out = array();

		$prep = '';
		while (strlen($search) && !in_array($search[0], array('+', '@'))) {
			$prep .= $search[0];
			$search = substr($search, 1);
		}
		if (empty($search)) {
			return array();
		}

		foreach ($this->todos as $todo) {
			foreach ($todo->projects as $project) {
				if ('+' . substr($project, 0, strlen($search) - 1) == $search) {
					$out[] = $prep . '+' . $project;
				}
			}
			foreach ($todo->contexts as $context) {
				if ('@' . substr($context, 0, strlen($search) - 1) == $search) {
					$out[] = $prep . '@' . $context;
				}
			}
		}
		return array_unique($out);
	}

	protected function columnValue($k, $column) {
		$todo = $this->todos[$k];

		$val = '';
		switch ($column) {
			case 'num':
				$val = $k;
			break;
			case 'done':
				if ($todo->done) {
					$val = 'X';
				}
			break;
			case 'due':
			case 'creationDate':
			case 'doneDate':
				if ($todo->$column) {
					$val = $todo->$column->format(Config::$config['gui']['date_format_out']);
				}
			break;
			case 'recurrent':
				if ($todo->recurrent) {
					$val = $todo->recurrent->toString();
				}
			break;
			case 'projects':
			case 'contexts':
				$val = implode(', ', $todo->$column);
			break;
			case 'text':
			case 'priority':
				$val = (string) $todo->$column;
			break;
		}
		return $val;
	}

	public function start() {
		$this->readLine = new ReadLine();
		$this->readLine->setCompletitionCallback(function($input) {
			return $this->readlineCompletion($input);
		});
		try {
			$this->readLine->historyLoad(Config::$config['gui']['history_file']);
		} catch (HistoryLoadException $hle) {
			echo $hle->getMessage() . PHP_EOL;
			exit(-1);
		}

		$this->todos->sort($this->sort);

		// Clear screen
		echo "\033c";
		while (true) {
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

			// Detect max length of every column
			$columns = Config::$config['gui']['columns'];
			$lengths = array();
			foreach ($columns as $column) {
				if (!isset($this->columns[$column])) {
					echo 'Unknow column: ' . $column . ', check configuration gui.columns!' . PHP_EOL;
					exit(-1);
				}
				$lengths[$column] = mb_strlen($this->columns[$column]['title']);
			}
			$pos = 0;
			foreach ($this->filteredTodos as $k=>$todo) {
				$pos++;
				foreach ($columns as $column) {
					$len = mb_strlen($this->columnValue($k, $column));
					if ($len > $lengths[$column]) {
						$lengths[$column] = $len;
					}
				}
				if (isset(Config::$config['gui']['max_todos']) && $pos >= Config::$config['gui']['max_todos']) {
					break;
				}
			}

			// Got to the begging of screen
			echo "\033[0;0H";

			// Show columns title
			$first = true;
			foreach ($columns as $column) {
				// Clear line
				echo "\033[K";

				if ($first) {
					$first = false;
				} else {
					echo '|';
				}
				echo $this->config2color(Config::$config['color']['title']);
				echo ' ' . mb_str_pad(
						$this->columns[$column]['title'],
						$lengths[$column],
						' ',
						$this->columns[$column]['padTitle']
					) . ' ';
				echo $this->config2color(Config::$config['color']['default']);
			}
			echo PHP_EOL;

			// Show todos
			$pos = 0;
			foreach ($this->filteredTodos as $k=>$todo) {
				// Clear line
				echo "\033[K";

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

				$first = true;
				foreach ($columns as $column) {
					if ($first) {
						$first = false;
					} else {
						echo '|';
					}
					echo ' ' . mb_str_pad(
							$this->columnValue($k, $column),
							$lengths[$column],
							' ',
							$this->columns[$column]['padValue']
						) . ' ';
				}

				echo $this->config2color(Config::$config['color']['default']);
				echo PHP_EOL;

				if (isset(Config::$config['gui']['max_todos']) && $pos >= Config::$config['gui']['max_todos']) {
					echo "\033[K..." . PHP_EOL;
					break;
				}
			}

			// Clear rest of screen
			echo "\033[0J";

			echo PHP_EOL;
			echo $this->message . PHP_EOL;

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
			$cmd = $this->readLine->read('> ', '', Config::$config['gui']['reload_timeout']);
			if ($this->readLine->timeout) {
				continue;
			}
			$this->readLine->historyAdd($cmd);
			$this->readLine->historySave(Config::$config['gui']['history_file']);
			if ($cmd === '' || $cmd === false) {
				continue;
			}

			$this->message = '';

			switch ($cmd[0]) {
				// Create new task
				case 'c':
					$text = trim(substr($cmd, 1));
					if (empty($text)) {
						$text = $this->readLine->read('Text: ');
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
							$confirm = $this->readLine->read('Really add (y/n)? ', 'y');
							if ($confirm !== 'y') {
								$this->error('Todo not added');
								break;
							}
						}

						$this->todos[] = $t;
						$this->lastLineNumber = array_pop($this->todos->array_keys());
						$this->save();
						$this->notice('Todo added');
					}
				break;

				// Edit task
				case 'e':
					$num = $this->getLineNumber($cmd);
					if ($num === null) {
						break;
					}
					$text = $this->readLine->read('Text: ', $this->todos[$num]->text);
					if ($text === '' || $text === false) {
						$this->error('Need text');
					} else {
						$this->todos[$num]->text = $text;
						$this->save();
						$this->notice('Todo ' . $num . ' changed');
					}
				break;

				// Remove task
				case 'r':
					$num = $this->getLineNumber($cmd);
					if ($num === null) {
						break;
					}
					$confirm = $this->readLine->read('Do you really want to remove todo ' . $num .' (y/n)? ', 'y');
					if ($confirm === 'y') {
						unset($this->todos[$num]);
						$this->save();
						$this->notice('Todo ' . $num . ' removed');
					} else {
						$this->error('Todo ' . $num . ' NOT removed');
					}
				break;

				// Archive
				case 'a':
					$count = $this->todos->archive(Config::$config['core']['archive_file']);
					if ($count) {
						$this->todos->sort($this->sort);
						$this->save();
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
					$this->save();
					$this->notice('Todo ' . $num . ' marked done');
				break;

				// Unmark as done
				case 'X':
					$num = $this->getLineNumber($cmd);
					if ($num === null) {
						break;
					}
					$this->todos[$num]->unmarkDone();
					$this->save();
					$this->notice('Todo ' . $num . ' unmarked done');
				break;

				// Set due date
				case 'd':
					$num = $this->getLineNumber($cmd);
					if ($num === null) {
						break;
					}
					$due = null;
					if ($this->todos[$num]->due) {
						$due = $this->todos[$num]->due->format(Config::$config['gui']['date_format_out']);
					}
					$str = $this->readLine->read('Due date: ', $due);
					if ($str === '' || $str === false) {
						$this->todos[$num]->due = null;
						$this->save();
						$this->notice('Due date unset for todo ' . $num);
					} else {
						try {
							$dt = $this->parseDate($str);
							$this->todos[$num]->due = $dt;
							$this->save();
							$this->notice('Due date set to ' . $dt->format('Y-m-d') . ' for todo ' . $num);
						} catch (DateParseException $dpe) {
							$this->error('Don\'t understand ' . $str);
						}
					}
				break;

				// Unset due date
				case 'D':
					$num = $this->getLineNumber($cmd);
					if ($num === null) {
						break;
					}
					$this->todos[$num]->due = null;
					$this->save();
					$this->notice('Due date unset for todo ' . $num);
				break;

				// Recurrent
				case 'g':
					$num = $this->getLineNumber($cmd);
					if ($num === null) {
						break;
					}
					$recurrent = null;
					if ($this->todos[$num]->recurrent) {
						$recurrent = $this->todos[$num]->recurrent->toString();
					}
					$str = $this->readLine->read('Recurrent: ', $recurrent);
					if ($str === '' || $str === false) {
						$this->todos[$num]->recurrent = null;
						$this->save();
						$this->notice('Todo ' . $num . ' set not recurrent');
					} else {
						try {
							$r = new Recurrent($str);
							$this->todos[$num]->recurrent = $r;
							$this->save();
							$this->notice('Todo ' . $num . ' set recurrent ' . $r->toString());
						} catch (RecurrentParseException $rpe) {
							$this->error('Don\'t understand ' . $str);
						}
					}
				break;

				// Unset recurrent
				case 'G':
					$num = $this->getLineNumber($cmd);
					if ($num === null) {
						break;
					}
					$this->todos[$num]->recurrent = null;
					$this->save();
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
						$search = $this->readLine->read('Search: ');
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
					$str = $this->readLine->read('Priority: ', $this->todos[$num]->priority);
					if ($str === '' || $str === false) {
						$this->notice('Priority unset for todo ' . $num);
						$this->todos[$num]->priority = null;
						$this->save();
					} else if (preg_match('/^[a-zA-Z]$/', $str)) {
						$prio = strtoupper($str);
						$this->todos[$num]->priority = $prio;
						$this->save();
						$this->notice('Priority set to ' . $prio . ' for todo ' . $num);
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
					$this->save();
					$this->notice('Priority unset for todo ' . $num);
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
