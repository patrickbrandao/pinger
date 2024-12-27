#!/usr/bin/php -q
<?php

	/*

		Realiza teste de fping em uma lista de IPs e retorna o JSON do resultado

		Recursos:
			- fping para fazer multiplos pings paralelamente
			- suporte de retorno em json
			- suporte a obter lista de IPs de uma URL
			- suporte a enviar o json de retorno para uma webhook
			- suporte ipv4 e ipv6

		Argumentos:
			use o comando seguido de --help para obter os detalhes, a primeira funcao _help() contem
			os detalhes

		Variaveis de ambiente: pegar o nome do argumento,
							   colocar em maiusculo e com underline "_" no lugar de hifem "-"

		Testes: coloque o fonte em /usr/bin/icmp-pinger-agent

			Exemplo 1: ping simples em ips do argumento

				icmp-pinger-agent 1.1.1.1

			Exemplo 2: ping simples com retorno json em arquivo e na tela

				icmp-pinger-agent 1.1.1.1 -output /tmp/cloudflare-dns-ping.json

			Exemplo 3: ping simples com retorno json, sem saida na tela

				icmp-pinger-agent 1.1.1.1 -output /tmp/cloudflare-dns-ping.json -quiet

			Exemplo 4: ping simples com retorno json, sem saida na tela, gravando em arquivo e enviando para webhook

				icmp-pinger-agent 1.1.1.1 -output /tmp/cloudflare-dns-ping.json -quiet -webhook-url http://srv91.tmsoft.com.br/http.php

			Exemplo 5: obter IPs de uma URL (websource) e enviar resultado para webhook

				icmp-pinger-agent -quiet -websource-url https://tmsoft.com.br/temp/dns-google.txt -webhook-url http://ws.intranet.br/pinger-icmp


			Exemplo 6: MODO REALTIME, rodar como servico de ping em loop infinito, pingar a cada 2 segundos, 1 ping por vez, pacote de 1200 bytes

				icmp-pinger-agent \
					-daemon \
					-quiet \
					-websource-url https://tmsoft.com.br/temp/dns-google.txt \
					-webhook-url http://ws.intranet.br/pinger-icmp \
					-pause 2 \
					-count 1 \
					-size 1200


			Exemplo 7: usando variaveis de ambiente para uso em Docker/Container:

				# Declare (-e no docker run)
				export COUNT=4
				export OUTPUT=/tmp/ping.json
				export FORMAT=json
				export TTL=16
				export FRAGMENT=no
				export RETRIES=0
				export TIMEOUT=2000

				export WEBSOURCE_URL=https://tmsoft.com.br/temp/dns-google.txt
				export WEBSOURCE_HEADERS="Authorization: xpto|X-Auth: tulipa"
				export WEBSOURCE_METHOD=GET
				export WEBSOURCE_CACHE=/tmp/pinger-source.txt

				export WEBHOOK_URL="http://ws.intranet.br/pinger-icmp"
				export WEBHOOK_HEADERS="X-Pinger: tulipa|X-Author: Patrick"
			
				# Execute (entrypoint ou cmd):
				icmp-pinger-agent \
						-daemon \
						-quiet


	*/

	// Funcao de exibir ajuda
	function _help(){
		echo "\n";
		echo "icmp-pinger-agent - ping icmp com retorno em json\n";
		echo "\n";
		echo "Argumentos principais:\n";
		echo "   -debug               - Ativar modo verbose para analise\n";
		echo "   -quiet               - Modo silencioso, nao exibe nada na tela\n";
		echo "   -daemon              - Modo daemon, ficam em loop infinito (nao faz fork)\n";
		echo "   -pidfile /run/...    - Arquivo de PID do processo (somente para modo daemon)\n";
		echo "   -output /tmp/...     - Arquivo de resultado dos pings\n";
		echo "   -format json|csv     - Formato do arquivo de resultado, json ou csv (padrao: json)\n";
		echo "   -pause S             - Pause entre loops no modo daemon (padrao: 30 segundos)\n";
		echo "\n";
		echo "Opcoes de ping ICMP:\n";
		echo "   -interval N          - Intervalo entre pacotes icmp em milisegundos (padrao: 2ms)\n";
		echo "   -size B              - Tamanho em bytes do pacote ICMP (padrao: 1000 bytes)\n";
		echo "   -count C             - Numero de pings por IP (padrao: 10)\n";
		echo "   -ttl T               - TTL de origem (padrao: 64)\n";
		echo "   -fragment yes/no     - Permitir fragmentacao? (padrao: yes)\n";
		echo "   -retries N           - Numero de repeticoes em caso de perda total (padrao: 0)\n";
		echo "   -timeout M           - Tempo limite de espera da resposta (padrao: 900 ms)\n";
		echo "\n";
		echo "Lista de IPs locais:\n";
		echo "   x.x.x.x              - IPv4 para pingar\n";
		echo "   x::x                 - IPv6 para pingar\n";
		echo "   /dir/file            - Caminho do arquivo local com lista de IPs (IPv4 e/ou IPv6)\n";
		echo "\n";
		echo "Lista de IPs fornecido por url:\n";
		echo "   -websource-url      http...           - WebSource URL      - URL da lista de IPs para pingar\n";
		echo "   -websource-headers  'Header: Value'   - WebSource Headers  - Cabecalhos HTTP a anexar no pedido da lista (separar por '|')\n";
		echo "   -websource-method   POST|GET          - Websource Method   - Metodo HTTP: POST ou GET\n";
		echo "   -websource-interval seconds           - Websource Interval - Intervalo entre downloads da lista de IPs\n";
		echo "\n";
		echo "WebHook de postagem de resultados:\n";
		echo "   -webhook-url       http...           - WebHook URL      - URL da lista de IPs para pingar\n";
		echo "   -webhook-headers   'Header: Value'   - WebHook Headers  - Cabecalhos HTTP a anexar no envio dos dados (separar por '|')\n";
		echo "\n";
		echo "\n";
		exit(1);
	}

	// Importar valor para inteiro, considerando
	// que pode ser palavra boleana
	function import_int($str){
		if($str=='y'||$str=='yes'||$str=='on') return 1;
		if($str=='n'||$str=='no'||$str=='off') return 0;
		return (int)$str;
	}
	// Ler palavra de metodo http e dar conformidade
	function read_http_method($str, $df='GET'){
		$str = strtolower($str);
		if($str=='get' ) return 'GET';
		if($str=='post') return 'POST';
		return $df;
	}

	// Verificar se e' IPv4 sintaticamente correto
	function ipv4_test($_ip){
        $_ip = trim($_ip);  
        $_len = strlen($_ip);  
        // testar tamanho  
        if($_len < 7 || $_len > 15) return false;  
        // testar partes, precista ter 3 pontos  
        if(substr_count($_ip, '.')!=3) return false;  
        $_parts = explode('.', $_ip);
        foreach($_parts as $k=>$v){  
            $v = (int)$v;  
            if($v<0||$v>255) return false;  
            $_parts[$k] = $v;  
        }  
        return true;
	}
	// Verificar se e' IPv6 sintaticamente correto
	function ipv6_test($addr){
		$read = ipv6_read($addr);
		return $read['valid'];
	}
	// ler endereco ipv6
	function &ipv6_read($ipv6){
		$info = array('valid' => false, 'input' => $ipv6, 'tokens'=>array(), 'tcount' => 0);
		$len = strlen($ipv6);
		$str = '';
		$jumpcount = 0;
		for($c=0; $c<$len; $c++){
			$chr = substr($ipv6, $c, 1);
			$ord = ord($chr);

			// 48-57 65-70 97-102
			if( ! ( $chr==':' || ($ord >= 48 && $ord <= 57) ||  ($ord >= 65 && $ord <= 70) || ($ord >= 97 && $ord <= 102) ) ){
				$info['errno'] = 1;
				$info['error'] = 'prohibited byte';
				return $info;
			}
			
			$dbl = substr($ipv6, $c, 2);
			// divisores invalidos
			if($dbl=='.:'||$dbl==':.'||$dbl=='..'){$info['errno'] = 3;$info['error'] = 'wrong sep found';return $info;}
			// divisores
			if($dbl=='::'){
				if($str!=''){ $info['tokens'][] = array(0 => $str, 1 => 1); $str=''; }
				if($jumpcount){$info['errno'] = 4;$info['error'] = 'double jump found';return $info;}
				$jumpcount++;
				$info['tokens'][]=array(0=>'::', 1=>4);
				$c++;
				continue;
			}
			if($chr==':'){
				if($str!=''){ $info['tokens'][] = array(0 => $str, 1 => 1); $str=''; }
				$info['tokens'][]=array(0=>':', 1=>3); continue;
			}
			// numeros
			$str .= $chr;
			//echo "CHR: $chr  DBL: $dbl\n";
		}
		if($str!=''){ $info['tokens'][] = array(0 => $str, 1 => 1); $str=''; }
		$info['tcount'] = count($info['tokens']);

		// sem saltos, mas nao tem todas as casas
		if(!$jumpcount && $info['tcount'] < 15){ $info['errno'] = 1; $info['error'] = 'less than 16 bytes'; return $info; }
	
		// excesso de numeros
		if($info['tcount']>15){ $info['errno'] = 5; $info['error'] = 'tokens overflow'; return $info; }
		
		// qualquer sequencia maior que 4 bytes
		foreach($info['tokens'] as $k=>$token)
			if(strlen($token[0])>4){ $info['errno'] = 6; $info['error'] = 'more than 4 bytes'; return $info; }
		//-
		$info['valid'] = 1;
		return $info;
	}

	// analisar texto e retira enderecos IP das linhas
	function text_get_ip_address($text){
		$iplist = array();
		$lines = explode("\n", $text);
		foreach($lines as $x=>$line){
			$_line = strtolower(trim($line));
			if($_line=='' || substr($_line, 0, 1) =='#') continue;
			// Endereco IPv4 ou IPv6
			if(ipv4_test($_line)||ipv6_test($_line)){
				$iplist[] = $_line;
			}
		}
		return $iplist;
	}

	// analisar arquivo e extrair enderecos IP das linhas
	function file_get_ip_address($file){
		$iplist = array();
		// Arquivo nao existe
		if(is_readable($file)){
			$iplist = text_get_ip_address(file_get_contents($file));
		}
		return $iplist;
	}

	// gerar uuid, obter uuid do kernel
	function generate_uuid(){
		$uuid = trim(file_get_contents('/proc/sys/kernel/random/uuid'));
		return $uuid;
	}

	// verificar se o cabecalho http e' sintaticamente correto
	function is_http_header($header){
		if(strpos($header, ': ')!==false) return true;
		return false;
	}
	// Importar cabecalhos http da string do argument/env
	function import_http_headers($str){
		$headers = array();
		$str = trim($str);
		$list = explode('|', $str);
		foreach($list as $k=>$line){
			if(is_http_header($line)) $headers[] = $line;
		}
		return $headers;
	}

	// Verificar se foi informada uma url
	function is_url($url){
		if(substr($url, 0, 7)=='http://') return true;
		if(substr($url, 0, 8)=='https://') return true;
		return false;
	}
	// Verificar se o caminho para o arquivo
	// e' utilizavel
	function is_filepath($path){
		if(substr($path, 0, 1)=='/') return 1;
		return 0;
	}

	// Executar um programa e retornar inforamcoes
	// Argumentos:
	//     cmd = comando
	//     input = dado a jogar na STDIN, opcional
	// Returno:
	//     array contando:
	//         stdout => saida STDOUT
	//         stderr => saida STDERR
	//         stdno => saida STDNO (0=ok, 1-127=problema)
	function &shell_execute($cmd, $input=''){
		$proc=proc_open(
			$cmd,
			array(
				0=>array('pipe', 'r'),
				1=>array('pipe', 'w'),
				2=>array('pipe', 'w')
			),
			$pipes
		);
		if($input!='') fwrite($pipes[0], $input);
		fclose($pipes[0]);
		$stdout=stream_get_contents($pipes[1]);
		fclose($pipes[1]);
		$stderr=stream_get_contents($pipes[2]);
		fclose($pipes[2]);
		$rtn=proc_close($proc);
		$ret = array(
			'cmd'    => $cmd,
			'input'  => $input,
			'stdout' => $stdout,
			'stderr' => $stderr,
			'stdno'  => $rtn
		);
		return $ret;
	}

	// tornar processo exclusivo e gravar PID, matar pid anterior apenas se estiver
	// rodando e contendo o nome do processo atual
	// Retornos:
	// 0=novo processo!
	// 1=processo concorrente finalizado
	// 2=processo precessor morreu
	function set_exclusive_pid_by_name($pidfile, $psname){
		$r = array(0=>getmypid(),1=>0);
		// nao informou pidfile, esquisito mesmo
		if($pidfile=='') return $r;
		// nao informou nome do processo, esquisito mesmo
		if($psname=='') return $r;
		// matar concorrencia
		$pid = is_file($pidfile) ? (int)file_get_contents($pidfile) : 0;
		if($pid){
			if(is_dir('/proc/'.$pid)){
				// obter nome do processo
				$cmdline = str_replace(chr(0), " ", file_get_contents('/proc/'.$pid.'/cmdline'));
				if(strpos($cmdline, $psname)!==false){
					// matar apenas se for do mesmo nome
					$r[1] = 1;
					for($i=0;$i<3;$i++)
						shell_exec("kill -9 ".$pid." 2>/dev/null 1>/dev/null");
					//-
				}
				// else: pid pertence a outro programa, deixar quieto
			}else{
				$r[1]=2;
			}
		}
		// gravar meu pid
		file_put_contents($pidfile, $r[0]);
		return $r;
	}

	// Realizar ping numa lista de hosts paralelamente
	function &icmp_fping($list, $pconf=false){
		global $MAIN_CONFIG;
		$debug = $MAIN_CONFIG['debug'];

		// Array de resultado
		$results = array();

		// Configuracao do ping
		$stdcfg = array(
			'size' => 64,
			'count' => 2,
			'ttl' => 128,
			'retries' => 1,
			'fragment' => 1,
			'interval' => 1,
			'timeout' => 900,
			'source_address' => ''
		);
		if(!is_array($pconf)) $pconf = $stdcfg;
		$pconf = array_merge($stdcfg, $pconf);

		// Critica de argumentos
		$size = $pconf['size'];

		// Tamanho do payload icmp,
		// o pacote real vai ser 8+20 para ipv4, 8+40 para ipv6
		if($size < 8) $size = 8;

		// Registro padrao
		// - address: fqdn, ipv4 ou ipv6 pingado
		// - status: 0=sem resposta, 1=com resposta, 2=com resposta mas com perda
		// - min: ping minimo, em microsegundos
		// - max: ping maximo, em microsegundos
		// - sent: pacotes enviados
		// - received: pacotes recebidos
		// - losts: pacotes perdidos
		// - avg: media do ping, em microsegundos
		// - total: total de microsegundos dos pings respondidos
		$stdreg = array(
			'address'  => '',
			'status'   => 0,
			'min'      => 0,
			'avg'      => 0,
			'max'      => 0,
			'total'    => 0,
			'sent'     => 0,
			'received' => 0,
			'losts'    => 0,
			'jitter'   => 0,
		);

		// remover linhas em brancos e mascaras de rede dos ips listados
		foreach($list as $k=>$ip){
			$ip = trim($ip);
			if($p=strpos($ip, '/')){ $ip=substr($ip, 0, $p); }
			if($ip==''){ unset($list[$k]); continue; }
			$list[$k] = $ip;
			$results[$ip] = $stdreg;
		}
		// Argumentos
		// - criticas
		if($pconf['timeout'] < 1) $pconf['timeout'] = 900;

		// IP de origem definido?
		$src_opt = '';
		if($pconf['source_address']!='') $src_opt = ' --src='.$pconf['source_address'];

		// Montar comando
		//- Argumentos
		$args = array();
		if(!$pconf['fragment']) $args[] = '--dontfrag';
		$args[] = '-C ' . $pconf['count'];
		$args[] = '-b ' . $size;
		$args[] = '--ttl ' . $pconf['ttl'];
		$args[] = '-q -B1 -r1';
		$args[] = '-i' . $pconf['interval'];
		$args[] = '--timeout='.$pconf['timeout'];
		if($pconf['source_address']!='') $args[] = ' --src='.$pconf['source_address'];
		$args[] = implode(' ', $list);

		// - Juntar comando e argumentos
		$cmd = 'fping '.implode(' ', $args);

		if($debug){
			echo "# fping, parametros:\n";
			echo "# - args..: "; print_r($args);
			echo "# - pconf.: "; print_r($pconf);
			echo "# - cmd...: ",$cmd,"\n";
		}

		// Argumentos do fping:
		// -C N  = numero de pings, mostrando latencia por IP
		// -q    = nao mostra resumo por host
		// -B N  = (nao sei o que faz)
		// -r N  = numero de tentativas
		// -i MS = numero de ms entre pacotes enviados

		// Executar comando
		// echo "#>>> CMD: $cmd\n";
		$areg = shell_execute($cmd);
		$stderr = trim($areg['stderr']);

		if($debug){
			echo "# fping, shell return:\n";
			echo "# - areg..: "; print_r($areg);
		}

		// echo "icmp_fping() debug:"; print_r($pconf);
		// echo "LIST:"; print_r($list);
		// echo "CMD: $cmd\n";
		// echo "stderr1:\n"; echo $stderr,"\n";

		// Limpar resultado
		$stderr = str_replace("\t", ' ', $stderr);
		$stderr = str_replace(" : ", '|', $stderr);
		// while(strpos($stderr, ': ')) $stderr = str_replace(': ', ':', $stderr);
		// while(strpos($stderr, ' :')) $stderr = str_replace(' :', ':', $stderr);
		// echo "stderr2:\n"; echo $stderr,"\n";

		// Quebrar linhas
		$lines = explode("\n", $stderr);

		//echo "lines:\n"; print_r($lines);

		foreach ($lines as $k=>$line){
			$d = strpos($line, '|'); if($d===false) continue;
			$ip = trim(substr($line, 0, $d));
			// Quase impossivel ter um ip na resposta que nao esteja na lista!
			if(!isset($results[$ip])) continue;
			// Resultados:
			$replies = trim(substr($line, $d+1));
			if($debug) echo "# - reply [$ip] = [$replies]\n";
			// - pacotes enviados
			$alat = explode(' ', $replies);
			$losts = 0;
			$sent = 0;
			$received = 0;
			$min = -1;
			$max = -1;
			$total = 0;
			foreach($alat as $m=>$n){
				$sent++;
				if($n=='-'){
					$n = 0;
					$losts++;
				}else $received++;
				$n = ( (float)($n) ) * 1000;
				if($min < 0 || $n < $min) $min = $n;
				if($max < 0 || $n > $max) $max = $n;
				$alat[$m] = $n;
				$total += $n;
			}
			if($min < 0) $min = 0;
			if($max < 0) $max = 0;
			$results[$ip]['address'] = $ip;
			$results[$ip]['sent'] = $sent;
			$results[$ip]['received'] = $received;
			$results[$ip]['losts'] = $losts;
			$results[$ip]['min'] = $min;
			$results[$ip]['max'] = $max;
			$results[$ip]['total'] = $total;
			$results[$ip]['jitter'] = $max - $min;
			$results[$ip]['avg'] = ($total && $received) ? (int)($total / $received) : 0;

			// Resumir status
			$results[$ip]['status'] = ($received ? ($losts ? 2 : 1) : 0);
		}
		//exit();
		return $results;
	}

	// Codifica array unidimencional em codigo javascript, usado para escapar erros de utf8 da função nativa
	function ujson_escape($_istr){
		$_replace_list = array(
			chr(34) => chr(92) . chr(34),
			chr(13) => '',
			chr(10)=> chr(92) . 'n'
		);
		foreach($_replace_list as $_old=>$_new)$_istr = str_replace($_old, $_new, $_istr);
		return($_istr);
	}

	// Codifica objeto php em formato json
	function ujson_encode($_input, $_checkaskey=false){
		// tipo numerico
		if(is_float($_input) || is_int($_input)){
			$_input = "" . $_input;
			if($_checkaskey) return(chr(34) . $_input . chr(34));
			return($_input);
		}
		// tipo boleano
		if(is_bool($_input)) return($_input?'true':'false');
		// tipo null
		if(is_null($_input) || is_object($_input)) return('""');
		// tipo string
		if(is_string($_input) || is_numeric($_input)) return(chr(34) . ujson_escape($_input) . chr(34));
		if(is_array($_input)){
			// se for verificacao para chave, nao permitir arrays
			if($_checkaskey) return(false);
			$_ret = "";
			// iniciar conversao de membros do array
			$_values = array(); // array de valores para modo simples
			foreach($_input as $_k=>$_v){
				$_k = ujson_encode($_k, true);
				// chave so pode ser string, pular essa, infelizmente
				if($_k===false) continue;
				$_values[] = $_k . ":" . ujson_encode($_v, false);
			}
			$_ret = "{" . implode(',', $_values) .  "}";
			return($_ret);
		}
		return '{}';
	}

	// verificar se um tempo limite passou
	// last_time: tempo do evento passado
	// act_time: tempo atual (timestamp, mtime ou utime)
	// timeout: tempo de diferenca entre os tempos para
	//       diferenca menor que timeout..: retorna TTL da informacao em cache, sempre 1 ou maior
	//       diferanca maior/igual timeout: returna zero
	// retorno:
	//       diferenca de tempo abaixo do timeout (TTL, tempo restante para timeout)
	function time_out($last_time, $act_time, $timeout=30){
		$expiretime = $last_time + $timeout;
		$diff = $expiretime - $act_time;
		if($diff < 0) $diff = 0;
		return $diff;
	}


	// Funcao para pegar lista de IPs de uma URL externa
	function websource_fetch(){
		global $MAIN_CONFIG;
		$iplist = array();

		// Variaveis da config principal usadas localmente
		$url = $MAIN_CONFIG['websource_url'];
		$method = $MAIN_CONFIG['websource_method'];
		$headers = $MAIN_CONFIG['websource_headers'];
		$debug = $MAIN_CONFIG['debug'];

		// Sem URL
		if($url=='') return $iplist;

		// Verificar se ja ta na hora de consultar novamente
		$nowts = @time();
		$lastupdate = $MAIN_CONFIG['websource_lastupdate'];
		$timeout = time_out($lastupdate, $nowts, $MAIN_CONFIG['websource_interval']);
		if($debug) echo "websource_fetch, timeout=$timeout\n";
		if($timeout){
			// Ainda ta passivel de uso no cache
			if($MAIN_CONFIG['websource_cachefile']!='' && is_file($MAIN_CONFIG['websource_cachefile'])){
				$iplist = text_get_ip_address($MAIN_CONFIG['websource_cachefile']);
				return $iplist;
			}
		}

		// Precisa pegar remotamente
    	// Metodo: get ou post
    	$method = read_http_method($method, 'GET');

    	// Buscar remotamente
		$ch = curl_init();
		curl_setopt($ch, CURLOPT_URL, $url);
		curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
		curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
		curl_setopt($ch, CURLOPT_HEADER, 1);
		// Opcoes globais da biblioteca
		curl_setopt($ch, CURLOPT_USERAGENT, 'icmp-pinger-agent');
		curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
		curl_setopt($ch, CURLOPT_TIMEOUT, 12);

		// Debug
		$debug_log = '/var/log/curl.log';
		if($debug && is_file($debug_log)){
			$loghandle = fopen($debug_log, 'w');
			curl_setopt($ch, CURLOPT_VERBOSE, $debug);
			curl_setopt($ch, CURLOPT_STDERR, $loghandle);
		}
		// Headers presente?
		if(is_array($headers) && count($headers)){
			curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
		}

		// Iniciar requisicao
		$response = curl_exec($ch);
		$info = curl_getinfo($ch);
		$header_size = $info['header_size'];

		// Separando os cabeçalhos do corpo
		$ret_headers = trim(substr($response, 0, $header_size));
		$ret_text = substr($response, $header_size);

		// Fechar resource CURL
		curl_close($ch);

		// Erro de requisicao
		$ret_code = (int)$info['http_code'];
		if($ret_code>=200 && $ret_code<=205){
			// Resultado funcional
			// Sucesso
			$iplist = text_get_ip_address($ret_text);

			// Atualizar timestamp da ultima aquisicao
			$MAIN_CONFIG['websource_lastupdate'] = $nowts;

			// Colocar em cache
			if($MAIN_CONFIG['websource_cachefile']!=''){
				file_put_contents($MAIN_CONFIG['websource_cachefile'], $webdata['text']);
			}
		}
		return $iplist;
	}

	// Funcao para enviar texto JSON via POST para webhook
	function webhook_send($payload){
		global $MAIN_CONFIG;
		$debug = $MAIN_CONFIG['debug'];

		// Variaveis da config principal usadas localmente
		$url = $MAIN_CONFIG['webhook_url'];
		$headers = $MAIN_CONFIG['webhook_headers'];
		$debug = $MAIN_CONFIG['debug'];

		// Sem URL
		if($url=='') return true;

		// Enviar conteudo para webhook
		if($debug){
			echo "webhook_send: enviando para $url\n";
			echo "webhook_send: headers: "; print_r($headers);
			echo "webhook_send: payload:\n$payload\n";
		}

    	// Registro de retorno padrao
    	$ret = array(
    		'cts' => 0,				// timestamp da aquisicao remota
    		'stdno' => 0,			// codigo de falha, 0=ok
    		'url' => $url,			// url base
    		'headers' => $headers,	// cabecalhos adicionais
    		'payload' => $payload,	// conteudo enviado no corpo
    		'code' => 0,			// codigo de retorno http, 0=erro fatal
    		'text' => '',			// texto obtido
    		'info' => array()		// informacoes CURL
    	);

    	// Timestamp atual
		$nowts = @time();
		$ret['cts'] = $nowts;

    	// Buscar remotamente
		$ch = curl_init();
		curl_setopt($ch, CURLOPT_URL, $url);
		curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
		curl_setopt($ch, CURLOPT_POST, true);
		curl_setopt($ch, CURLOPT_HEADER, 1);

		// Opcoes globais da biblioteca
		curl_setopt($ch, CURLOPT_USERAGENT, 'icmp-pinger-agent');
		curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
		curl_setopt($ch, CURLOPT_TIMEOUT, 12);

		// Debug
		$debug_log = '/var/log/curl.log';
		if($debug && is_file($debug_log)){
			$loghandle = fopen($debug_log, 'w');
			curl_setopt($ch, CURLOPT_VERBOSE, $debug);
			curl_setopt($ch, CURLOPT_STDERR, $loghandle);
		}

		// Manifestar tamanho do payload
		$clen = strlen($payload);
		if($clen){
			$headers[] = 'Content-Type: application/json';
			$headers[] = 'Content-Length: '.$clen;
		}

		// Anexar cabealhos
		if(count($headers)) curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

		// Anexar payload
		if($clen) curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);

		// Iniciar requisicao
		$response = curl_exec($ch);
		$info = curl_getinfo($ch);
		$header_size = $info['header_size'];

		// Separando os cabeçalhos do corpo
		$ret['headers'] = trim(substr($response, 0, $header_size));
		$ret['text'] = substr($response, $header_size);
		$ret['info'] = $info;

		// Fechar resource CURL
		curl_close($ch);

		// Erro de requisicao
		$ret['code'] = (int)$info['http_code'];
		if($ret['code']>=200 && $ret['code']<=205){
			// Resultado funcional
			if($debug) echo "# Webhook enviado com sucesso\n";
			$ret['stdno'] = 0;
			// Atualizar timestamp da ultima aquisicao
			$MAIN_CONFIG['webhook_lastupdate'] = @time();
			// Colocar retorno completo em cache serializado
			if($MAIN_CONFIG['webhook_cachefile']!=''){
				file_put_contents($MAIN_CONFIG['webhook_cachefile'], serialize($ret));
			}
		}else{
			// Deu algo errado
			if($debug) echo "# Webhook falhou, codigo de erro ",$ret['code'],"\n";
			$ret['stdno'] = $ret['code'];
		}
		// debug do retorno
		if($debug){
			echo "webhook_send: curl debug info:"; print_r($ret);
		}
		return $ret;
	}


	//==================================================================================================================================

	// Tempo universal
	date_default_timezone_set('UTC');

	// Sem tempo limite para operacoes em daemon
	set_time_limit(0);

	// Argumentos
	// Verbosidade
	$debug = 0;
	$quiet = 0;

	// Modo daemon, ficar em loop infinito
	$daemon = 0;

	// Listas para puxar IPs de alvo dos pings
	$file_list = array(); // lista de arquivos com IPs
	$ipv4_list = array(); // lista de IPv4 principal
	$ipv6_list = array(); // lista de IPv6 principal

	// Config principal
	$MAIN_CONFIG = array(
		// Nome/identificacao desse agente
		'name' => '',

		// Arquivo de pid	
		'pidfile' => '/run/icmp-pinger-agent.pid',

		// Saida de resultado
		'output' => '',
		'format' => 'json',

		// intervalo entre rotina de pings (segundos)
		'interval' => 10,

		// pause entre testes no loop do programa (segundos)
		'pause' => 30,

		// tamanho do pacote ICMP
		'size' => 1000,

		// numero de pings por ip
		'count' => 10,

		// TTL dos pacotes
		'ttl' => 64,

		// Repeticoes em caso de ausencia na resposta
		'retries' => 0,

		// Permitir fragmentacao
		'fragment' => 1,

		// Tempo limite para esperar respostas (ms)
		'timeout' => 900,

		// modo websource, url para baixar lista de ips
		'websource_url' => '',
		'websource_method' => 'GET',
		'websource_headers' => array(),		// cabecalhos adicionais no http
		'websource_interval' => 0,			// 0 puxa a cada loop, ou o numero de segundos entre atualizacoes
		'websource_lastupdate' => 0,		// timestamp da ultima atualizacao
		'websource_cachefile' => '', 		// arquivo local de cache

		// modo webhook, enviar json para site remoto
		'webhook_url' => '',
		'webhook_headers' => array(),		// cabecalhos adicionais no http
		'webhook_lastupdate' => 0,			// timestamp da ultima postagem na webhook
		'webhook_cachefile' => ''			// arquivo local de cache
	);

	// Preservar config inicial para ter como padrao
	$STD_CONFIG = $MAIN_CONFIG;


	// Processar argumentos
	//==================================================================================================================================

	// Remover argumento 0
	if(isset($argv[0])) unset($argv[0]);

	// Carregar configuracao das variaveis de ambiente
	$_inputs = array_merge($_ENV, $_SERVER, $argv);

	// Entradas indesejadas
	if( isset($_inputs['argv'])      ) unset($_inputs['argv']);
	if( isset($_inputs['LS_COLORS']) ) unset($_inputs['LS_COLORS']);

	// Extrair IPs (IPv4 ou IPv6) dos argumentos
	foreach($_inputs as $k=>$value){
		if(is_int($k)){
			$_arg = strtolower(trim($value));
			// Endereco IPv4 principal
			if(ipv4_test($_arg)){ $ipv4_list[] = $_arg; unset($_inputs[$k]); continue; }
			// Endereco IPv6 principal
			if(ipv6_test($_arg)){ $ipv6_list[] = $_arg; unset($_inputs[$k]); continue; }
		}
	}

	// Carregar configuracoes dos argumentos do comando
	foreach($_inputs as $vname=>$value){

		// Argumento removido pelo laço anterior
		if(!isset($_inputs[$vname])) continue;

		// Array nao faz parte do interesse
		if(is_array($value)) continue;

		// Nome do valor em minusculo e sem prefixo '-' ou '--'
		$_vname = ltrim(trim(strtolower($vname)), '-');

		//echo "# Analisar input: vname=[$vname] value=[$value], _vname=[$_vname]\n";

		// Se o nome da variavel estiver no array de config,
		// puxar valor pra dentro
		if(isset($MAIN_CONFIG[$_vname])){
			// Chave encontrada
			$oldvle = $MAIN_CONFIG[$_vname];
			$newvle = is_int($oldvle) ? import_int($value) : trim($value);
			if($debug) echo "# Config importada do ambiente: [$_vname]=[$newvle]\n";
			$MAIN_CONFIG[$_vname] = $newvle;
			continue;
		}

		// Se o nome da variavel for numerica, trata-se
		// de argumento na linha de comando (argv)
		// e pode aparecer em uma dessas formas:
		//  var value
		//  var=value
		//  --var=value
		//  -var=value
		//  var=value
		// Ignorar argumentos nao-inteiro
		if(!is_int($vname)) continue;

		// Entramos nos argumentos da linha de comando
		// que acionou o programa
		$k = $vname;
		$v = $k;
		$arg = trim($value);
		$_arg = strtolower(trim($arg));
		$vname = $_arg;
		$value = '';

		// Argumento vazio, ignorar
		if($_arg == '') continue;

		// chave=valor
		$p = strpos($arg, '=');
		if($p===false){
			// usar valor do arg seguinte
			if(isset($_inputs[$k+1])){
				$v = $k+1;
				$value = $_inputs[$v];
			}
		}else{
			// chave=valor presente
			$vname = trim(substr($_arg, 0, $p));
			$value = trim(substr($arg, $p+1));
		}
		// retirar '-' e '--' no inicio do argumento
		$_vname = ltrim(strtolower($vname), '-');
		$_value = strtolower($value);

		// Exibir ajuda
		if( $_vname=='help' || $_vname=='h' ){ _help(); }

		if($debug) echo "# Analisar argumento: vname=[$vname] value=[$value], _vname=[$_vname] value=[$value] _value=[$_value]\n";

		// Argumentos de flag (sem valor posterior)
		// - Ativar modo verbose/debug
		if( $_vname=='debug' ){
			$debug = 1; $quiet = 0;
			if(isset($_inputs[$k])) unset($_inputs[$k]);
			continue;
		}
		// - Desativar modo verbose totalmente
		if( $_vname=='quiet' ){
			$debug = 0; $quiet = 1;
			if(isset($_inputs[$k])) unset($_inputs[$k]);
			continue;
		}
		// - Ativar modo daemon (sem fork, apenas mantem o loop infinito)
		if( $_vname=='daemon' ){
			$daemon = 1;
			if(isset($_inputs[$k])) unset($_inputs[$k]);
			continue;
		}

		// Testar variavel no array com '_' para argumento informado com '-'
		if(!isset($MAIN_CONFIG[$_vname])){
			$tmp = str_replace('-', '_', $_vname);
			if(isset($MAIN_CONFIG[$tmp])){
				$_vname = $tmp;
			}
		}

		// Argumentos com valor posterior
		if(isset($MAIN_CONFIG[$_vname])){
			// Chave encontrada
			$oldvle = $MAIN_CONFIG[$_vname];
			$newvle = is_int($oldvle) ? import_int($value) : trim($value);
			$MAIN_CONFIG[$_vname] = $newvle;
			if($debug) echo "# Config importada de argumento: [$_vname]=[$value]\n";
			if(isset($_inputs[$k])) unset($_inputs[$k]);
			if(isset($_inputs[$v])) unset($_inputs[$v]);
			continue;
		}
		// Arquivo com lista de IPs
		if(is_filepath($arg) && is_file($arg)){
			$file_list[] = $arg;
			continue;
		}

		// Parametro desconhecido ou argumento desconhecido
		if($debug) echo "# Argumento desconhecido: $arg\n";
	}

	// Criticar valores

	if($debug){
		echo "PRE-Critica: "; print_r($MAIN_CONFIG);
	}

	// - Nome do agente local
	$name = $MAIN_CONFIG['name'];
	if($MAIN_CONFIG['name']==''){
		$name = trim(file_get_contents('/etc/HOSTNAME'));
		if($name=='') $name = trim(file_get_contents('/proc/sys/kernel/hostname'));		
		if($name=='') $name = trim(file_get_contents('/etc/machine-id'));
		if($name=='') $name = 'icmp-pinger-agent';
		$MAIN_CONFIG['name'] = $name;
	}

	// Arquivos para gravar

	// - Arquivo com o PID
	if(!is_filepath($MAIN_CONFIG['pidfile'])) $MAIN_CONFIG['pidfile'] = '';
	if($daemon && $MAIN_CONFIG['pidfile']==''){
		$MAIN_CONFIG['pidfile'] = '/run/icmp-pinger-agent.pid';
	}

	// - Arquivo de saida
	if(!is_filepath($MAIN_CONFIG['output']) ) $MAIN_CONFIG['output'] = '';
	// - Formato de saida
	if($MAIN_CONFIG['format']!='json' && $MAIN_CONFIG['format']!='csv'){
		$MAIN_CONFIG['format'] = 'json';
	}

	// - Faixas de valores aceitaveis

	// Intervalo entre 1 e 10000 ms (1ms a 10s)
	if($MAIN_CONFIG['interval'] < 1 || $MAIN_CONFIG['interval'] > 10000){
		$MAIN_CONFIG['interval'] = 10;
	}

	// Pause entre loops, entre 0s e 1hora
	if( $MAIN_CONFIG['pause'] < 0 || $MAIN_CONFIG['pause'] > 3600 ){
		$MAIN_CONFIG['pause'] = 30;
	}

	// Tamanho de payload ICMP
	if( $MAIN_CONFIG['size'] <    64 ) $MAIN_CONFIG['size'] =    64;
	if( $MAIN_CONFIG['size'] > 65488 ) $MAIN_CONFIG['size'] = 65488;

	// Contagem de pings por teste
	if( $MAIN_CONFIG['count'] <   1 ) $MAIN_CONFIG['count'] =  1;
	if( $MAIN_CONFIG['count'] > 100 ) $MAIN_CONFIG['count'] = 100;

	// Ttl de origem
	if( $MAIN_CONFIG['ttl'] <   1 ) $MAIN_CONFIG['ttl'] =  64;
	if( $MAIN_CONFIG['ttl'] > 255 ) $MAIN_CONFIG['ttl'] = 255;

	// Repeticao de ping perdido
	if( $MAIN_CONFIG['retries'] <   0 ) $MAIN_CONFIG['retries'] =  0;
	if( $MAIN_CONFIG['retries'] >  10 ) $MAIN_CONFIG['retries'] = 10;

	// Timeout de espera de resposta (em ms)
	if( $MAIN_CONFIG['timeout'] <     10 ) $MAIN_CONFIG['timeout'] =    10;
	if( $MAIN_CONFIG['timeout'] >  10000 ) $MAIN_CONFIG['timeout'] = 10000;

	// WebSource
	if(!is_url($MAIN_CONFIG['websource_url'])) $MAIN_CONFIG['websource_url'] = '';
	$MAIN_CONFIG['websource_method'] = read_http_method($MAIN_CONFIG['websource_method'], 'GET');
	if( $MAIN_CONFIG['websource_interval'] <    60 ) $MAIN_CONFIG['websource_interval'] =   60;
	if( $MAIN_CONFIG['websource_interval'] >  3600 ) $MAIN_CONFIG['websource_interval'] = 3600;
	if(!is_filepath($MAIN_CONFIG['websource_cachefile']) ) $MAIN_CONFIG['websource_cachefile'] = '';
	if(!is_array($MAIN_CONFIG['websource_headers'])){
		$MAIN_CONFIG['websource_headers'] = import_http_headers($MAIN_CONFIG['websource_headers']);
	}

	// WebHook
	if(!is_url($MAIN_CONFIG['webhook_url'])) $MAIN_CONFIG['webhook_url'] = '';
	if(!is_array($MAIN_CONFIG['webhook_headers'])){
		$MAIN_CONFIG['webhook_headers'] = import_http_headers($MAIN_CONFIG['webhook_headers']);
	}
	if(!is_filepath($MAIN_CONFIG['webhook_cachefile']) ) $MAIN_CONFIG['webhook_cachefile'] = '';

	if($debug){
		echo "POS-Critica: "; print_r($MAIN_CONFIG);
	}
	$MAIN_CONFIG['debug']  = $debug;
	$MAIN_CONFIG['daemon'] = $daemon;
	$pause = $MAIN_CONFIG['pause'];
	// exit();

	// Preparativos iniciais
	//==================================================================================================================================

	// Config de ping padrao
	// Config de operacao a reportar
	$ping_config = array(
		'type'            => 'icmp-echo',
		'name'            => $MAIN_CONFIG['name'],
		'interval'        => $MAIN_CONFIG['interval'],
		'pause'           => $MAIN_CONFIG['pause'],
		'size'            => $MAIN_CONFIG['size'],
		'count'           => $MAIN_CONFIG['count'],
		'ttl'             => $MAIN_CONFIG['ttl'],
		'retries'         => $MAIN_CONFIG['retries'],
		'fragment'        => $MAIN_CONFIG['fragment'],
		'timeout'         => $MAIN_CONFIG['timeout']
	);

	// Argumentos
	if($debug){
		echo "# Configuracao:\n";
		echo "# main config:\n"; print_r($MAIN_CONFIG);
		echo "\n";
		echo "# ping config:\n"; print_r($ping_config);
		echo "\n";
		echo "# ipv4_list..........: "; print_r($ipv4_list);
		echo "# ipv6_list..........: "; print_r($ipv6_list);
		echo "# file_list..........: "; print_r($file_list);
		echo "\n";
	}


	// Programa
	//==================================================================================================================================

	// Nao rodar em concorrencia, gravar PID para modo daemon
	if($daemon) set_exclusive_pid_by_name($MAIN_CONFIG['pidfile'], 'icmp-pinger-agent');

	// loop infinito
	while(true){
		if($debug) echo "# Iniciando icmp agent\n";

		// Obter lista de IPs da URL remota
		if($debug) echo "# Iniciando lista via websource\n";
		$web_list =& websource_fetch();

		// Carregar arquivos
		$file_targets = array();
		if(count($file_list)){
			if($debug) echo "# Iniciando lista de arquivos locais\n";
			foreach($file_list as $k=>$file){
				$tmp = file_get_ip_address($file);
				$c = count($tmp);
				if($c) $file_targets = array_merge($file_targets, $tmp);
				if($debug) echo "# $c IPs carregados do arquivo $file\n";
			}
		}

		// Lista fixa de IPs informados no argumento
		if($debug) echo "# Agregando listas\n";
		$targets = array_merge($ipv4_list, $ipv6_list, $web_list, $file_targets);

		// Remover duplicacoes
		$tmp = array();
		foreach($targets as $k=>$addr) $tmp[$addr] = $k;
		$targets = array_keys($tmp);
		// Total de alvos
		$total = count($targets);
		if($debug){
			echo "# $total IPs para pingar\n";
			print_r($targets);
		}

		// Pingar
		if($total){
			if($debug) echo "# Chamando fping\n";

			// Chamar teste
			$start_timestamp = @time();
			$fping_table =& icmp_fping($targets, $ping_config);
			$stop_timestamp = @time();
			$ellapsed_time = $stop_timestamp - $start_timestamp;

			// Identificador unico do teste
			$uuid = generate_uuid();

			// Singularizar o teste com a identidade unica
			// e o ponto unico no tempo
			$event = array(
				'uuid'            => $uuid,
				'start_datetime'  => date('c', $start_timestamp),
				'start_timestamp' => $start_timestamp,
				'stop_datetime'   => date('c', $stop_timestamp),
				'stop_timestamp'  => $stop_timestamp,
				'ellapsed_time'   => $ellapsed_time,
			);

			if($debug){
				echo "# Resultado fping:\n";
				print_r($fping_table);
			}
			// Compor JSON de resultado
			if($MAIN_CONFIG['format']=='json'){

				// Resultado em JSON
				$JSON = array();
				$JSON['event']       = $event;
				$JSON['ping_config'] = $ping_config;

				// Ajustar tabela para registros de chaves numericas
				$ping_summary = array(
					'hosts'            => array(),
					'hosts_online'     => array(),
					'hosts_offline'    => array(),
					'total_online'     => 0,
					'total_offline'    => 0,
					'packets_sent'     => 0,
					'packets_received' => 0,
					'packets_lost'     => 0
				);
				$ping_table = array();
				$id = 1;
				foreach($fping_table as $k=>$reg){
					$ping_table[$id] = $reg;
					$ping_summary['hosts'][$id]        = $reg['address'];
					$ping_summary['packets_sent']     += $reg['sent'];
					$ping_summary['packets_received'] += $reg['received'];
					$ping_summary['packets_lost']     += $reg['losts'];
					if($reg['status']){
						$ping_summary['total_online']++;
						$ping_summary['hosts_online'][$id] = $reg['address'];
					}else{
						$ping_summary['total_offline']++;
						$ping_summary['hosts_offline'][$id] = $reg['address'];
					}
					$id++;
				}
				$JSON['ping_summary'] = $ping_summary;
				$JSON['ping_table']   = $ping_table;
				// $JSON['fping_table']  = $fping_table;

				// JSON para text
				$JSON_TEXT = ujson_encode($JSON);

				// Modo quiet, nao exibir
				if($debug) print_r($JSON);
				if(!$quiet) echo $JSON_TEXT,"\n";

				// Gravar localmente
				if($MAIN_CONFIG['output']!=''){
					if($debug) echo "# Granvando JSON no arquivo local ",$MAIN_CONFIG['output'],"\n";
					file_put_contents($MAIN_CONFIG['output'], $JSON_TEXT);
				}

				// Webhook presente?
				if($MAIN_CONFIG['webhook_url']!=''){
					webhook_send($JSON_TEXT);
				}

			}else{
				// Resultado em CSV
				echo "Falta fazer CSV\n";
			}

		}else{
			if($debug) echo "# Sem IPs para pingar\n";
		}

		// modo one-shot
		if(!$daemon){
			if($debug) echo "# Modo one-shot, encerrando\n";
			break;
		}

		// Pause entre loops
		if($debug) echo "# Aguardando proximo loop, pause de $pause segundos\n";
		sleep($pause);

		// Limpeza constante de lixo
		gc_collect_cycles();

	}

	// Encerrar

	// - Apagar pidfile
	if($MAIN_CONFIG['pidfile']!='' && is_file($MAIN_CONFIG['pidfile'])){
		@unlink($MAIN_CONFIG['pidfile']);
	}


	exit();




?>
