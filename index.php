<?php
set_time_limit(0);
header('Content-type:text/html;charset=utf-8');

$date = U::getValue($_GET,'date','2016-09-06'); 	// 购票日期
$from = U::getValue($_GET,'form','襄阳'); 		// 起点
$to   = U::getValue($_GET,'to','北京'); 		// 终点
$min  = 1;				// 最小余票量。如：余票量大于 1 就提醒我，否者忽略

$url = C::getListsUrl($date, Z::getName($from), Z::getName($to));
$result = U::curlRequest($url); // 获取列车表


if( ($data = U::getDataByJson($result)) === false ){
	U::end('获取列车失败');
}

// 保存需要操作的列车
M::saveAs($data);

// 立即检测车次
M::checkIsBuy($data);

// 计算备选车次
M::computeAlternateTrain($data); // 计算备选车次

// 检查可用车次信息
M::checkingLieche();


// 业务处理功能类
class M{

	// 预定义可购买列车
	public static $buyArr = array();
	// 需要遍历的车次的信息
	public static $trainArr = array();
	// 需要遍历的站点
	public static $stationArr = array();
	// 需要遍历的站点
	public static $stationNewArr = array();
	// 需要遍历的车次
	public static $As = array();


	/**
	 * 判断返回的余票量是否有效
	 *
	 * @param $val
	 * @return bool
	 */
	public static function rNumber($val){
		
		if($val == '有'){
			return true;
		}elseif( intval($val) >= $GLOBALS['min'] ){
			return true;
		}

		return false;
	}

	/**
	 * 计算备选车次
	 */
	public static function computeAlternateTrain($data){
		self::foreachTrain($data); // 获取所有列车信息
		self::foreachStation(); // 遍历站点
		self::dealWithDika(); // 处理笛卡尔积结果
	}

	/**
	 * 检查可用车次
	 */
	public static function checkingLieche(){
		foreach(self::$stationNewArr as $v){
			$url = C::getListsUrl($GLOBALS['date'], Z::getName($v['start']), Z::getName($v['end']));
            
			$result = U::curlRequest($url); // 获取列车表
			if( ($data = U::getDataByJson($result)) ){
				self::checkIsBuyByThis($data);
			}
		}

	}

	/**
	 * 处理笛卡尔积结果
	 */
	public static function dealWithDika(){
		foreach(self::$stationArr as $item){
			foreach($item as $v){
				$tArr1 = explode('|',$v[0]);
				$tArr2 = explode('|',$v[1]);

				$arr = array();
				$arr['start'] = $tArr1[0];
				$arr['end'] = $tArr2[0];
				$arr['order'] = $tArr1[1] + $tArr2[1];
				if($arr['order'] > 0){ // 当前站点就不再重复查询了
					self::$stationNewArr[$arr['start'].'-'.$arr['end']] = $arr;
				}

			}
		}
        
		// 按照跨站的距离排序
		self::$stationNewArr = U::array_sort(self::$stationNewArr,'order','asc');
	}

	/**
	 * 遍历站点
	 */
	public static function foreachStation(){

		/**
		 * 第一次遍历：
		 * 1. 给每个站点加上序号。(index)
		 * 2. 找到当次查询的列车行程起点的序号与站名，并标记到列车起点信息中
		 * 3. 找到当次查询的列车行程终点的序号与站名，并标记到列车起点信息中
		 */
		foreach(M::$trainArr as $key => $item){

			$_sBol = false; // 预定义状态游标
			$from_station_name = '';
			$from_station_index = 1;
			$to_station_name = '';
			$to_station_index = 1;
			foreach($item as $k => $v){
				// 给站点加上序号
				M::$trainArr[$key][$k]->index = intval(U::getValue($v,'station_no'));

				if(  $_sBol === true && U::getValue($v,'isEnabled') === true ){
					// 默认找到行程起点后，任何一个有效站点当做行程终点站
					$to_station_name = U::getValue($v,'station_name');
					$to_station_index = intval(U::getValue($v,'station_no'));
				}elseif( $_sBol === false && U::getValue($v,'isEnabled') === true){  // 找到起点站
					$from_station_name = U::getValue($v,'station_name');
					$from_station_index = intval(U::getValue($v,'station_no'));
					$_sBol = true; // 修改游标状态
				}
				elseif( $_sBol === true && U::getValue($v,'isEnabled') === false ){  // 找到行程终点
					$_sBol = false; // 还原游标状态
				}
			}

			// 将行程起点，序号记录到首行信息
			M::$trainArr[$key][0]->from_station_name 	= $from_station_name;
			M::$trainArr[$key][0]->from_station_index 	= $from_station_index;
			M::$trainArr[$key][0]->to_station_name 		= $to_station_name;
			M::$trainArr[$key][0]->to_station_index 	= $to_station_index;
		}

		/**
		 * 第二次遍历：
		 * 1. 取出行程起点站的序号
		 * 2. 取出行程终点站的序号
		 * 3. 取出 列车起点站 - 行程起点站 的集合站点
		 * 4. 取出 行程终点站 - 列车终点站 的集合站点
		 * 5. 传入 笛卡尔算法 计算出新的查询站点
		 */

		foreach(M::$trainArr as $key => $item) {

			// 取出行程信息及起点序号
			$from_station_name 	= U::getValue($item[0],'from_station_name');
			$from_station_index = U::getValue($item[0],'from_station_index');
			$to_station_name 	= U::getValue($item[0],'to_station_name');
			$to_station_index 	= U::getValue($item[0],'to_station_index');

			// 取出列车编号
			$station_train_code = U::getValue($item[0],'station_train_code');

			// 预定义超前站点集
			$beforeSizesArr = array();
			$backSizesArr = array();

			// 取两端极至站点
			foreach ($item as $k => $v) {
				$index = intval(U::getValue($v,'index',0));
				if($index <= $from_station_index){
					$beforeSizesArr[] = U::getValue($v,'station_name','').'|'.abs($from_station_index - $index);
				}elseif($index >= $to_station_index){
					$backSizesArr[] = U::getValue($v,'station_name','').'|'.abs($index - $to_station_index);
				}
			}

			// 送入笛卡尔算法
			self::$stationArr[] = U::combineDika($beforeSizesArr,$backSizesArr); # 计算笛卡尔积
		}
       
	}


	// 提取需要遍历的车次所有站点
	public static function foreachTrain($data){
		// 遍历当前行程的车次
		foreach ($data as $val) {
			//$row = U::getValue($val,'queryLeftNewDTO');
			$row = $val;

			$train_no = U::getValue($val,'train_no',0); // 列车编号

			$start_station_telecode = U::getValue($row,'start_station_telecode'); // 列车起点站代号
			$start_station_name = U::getValue($row,'start_station_name'); // 列车起点站名称
			$end_station_telecode = U::getValue($row,'end_station_telecode'); // 列车终点站代号
			$end_station_name = U::getValue($row,'end_station_name');	// 列车终点站名称

			$from_station_telecode = U::getValue($row,'from_station_telecode'); // 行程起点代号
			$from_station_name = U::getValue($row,'from_station_name'); // 行程起点名称
            
			$to_station_telecode = U::getValue($row,'to_station_telecode'); // 行程终点代号
			$to_station_name = U::getValue($row,'to_station_name'); // 行程终点名称
            
			$seat_types = U::getValue($row,'seat_types'); // 
			
			$url = C::getStationUrl($train_no,$GLOBALS['date'],$from_station_telecode,$to_station_telecode,$seat_types);
			
            $result = U::curlRequest($url);
            
     
			if( $data = U::getDataByJson2($result)){
				$tmpArr = U::getValue($data,'data',array());
				if(!empty($tmpArr)){
					M::$trainArr[] = $tmpArr;
				}
				
			}
			
		}
	}

	/**
	 * 保存需要操作的车次
	 */
	public static function saveAs($data = array()){
		foreach ($data as $val) {
			//$row = U::getValue($val,'queryLeftNewDTO');
			$row = $val;
			self::$As[U::getValue($row,'station_train_code')] = array(
				'station_train_code' => U::getValue($row,'station_train_code'),
				'train_no' => U::getValue($row,'train_no'),
			);

		}
	}

	/**
	 * 检测车次列表是否有可购买的车次
	 */
	public static function checkIsBuy($data = array()){
		foreach ($data as $val) {
			//$row = U::getValue($val,'queryLeftNewDTO');
			$row = $val;

			$yz_num = M::rNumber(U::getValue($row,'yz_num',0)); // 硬座
			$yw_num = M::rNumber(U::getValue($row,'yw_num',0)); // 硬卧
			$ze_num = M::rNumber(U::getValue($row,'ze_num',0)); // 二等座
			$zy_num = M::rNumber(U::getValue($row,'zy_num',0)); // 一等座

			if( $yz_num || $yw_num || $ze_num || $zy_num ){	// 只要有一个座位等次符合余量就加入待选队列
				self::$buyArr[] = $row;
			}

		}
	}

	/**
	 * 检测车次列表是否有可购买的车次,只选择目标行程中出现的车次
	 */
	public static function checkIsBuyByThis($data = array()){
		foreach ($data as $val) {
			//$row = U::getValue($val,'queryLeftNewDTO');
			$row = $val;
            
			$station_train_code = U::getValue($row,'station_train_code','');
			if(isset(self::$As[$station_train_code])){
				$yz_num = M::rNumber(U::getValue($row,'yz_num',0)); // 硬座
				$yw_num = M::rNumber(U::getValue($row,'yw_num',0)); // 硬卧
				$ze_num = M::rNumber(U::getValue($row,'ze_num',0)); // 二等座
				$zy_num = M::rNumber(U::getValue($row,'zy_num',0)); // 一等座

				if( $yz_num || $yw_num || $ze_num || $zy_num ){	// 只要有一个座位等次符合余量就加入待选队列
					self::$buyArr[] = $row;
				}
			}


		}
	}

}
// 业务处理功能类

// 配置类
class C{
	/**
	 * 获取列车列表URL
	 */
	public static function getListsUrl($date = '',$from = '',$to = ''){
		return "https://kyfw.12306.cn/otn/lcxxcx/query?purpose_codes=ADULT&queryDate={$date}&from_station={$from}&to_station={$to}";
		//return "https://kyfw.12306.cn/otn/leftTicket/queryT?leftTicketDTO.train_date={$date}&leftTicketDTO.from_station={$from}&leftTicketDTO.to_station={$to}&purpose_codes=ADULT";
	}

	/**
	 * 获取列车站点URL
	 */
	public static function getStationUrl($train_no = '',$date = '',$from = '',$to = '', $seat_types = ''){
		return "https://kyfw.12306.cn/otn/czxx/queryByTrainNo?train_no={$train_no}&from_station_telecode={$from}&to_station_telecode={$to}&depart_date={$date}";
		//return "https://kyfw.12306.cn/otn/czxx/queryByTrainNo?train_no={$train_no}&from_station_telecode={$from}&to_station_telecode={$to}&depart_date={$date}";
	}


}
// 配置类 End


// 功能类
class U{

	// 检查
	public static function getDataByJson($result){
		if(self::isJson($result)){
			$json = json_decode($result);
			if( (self::getValue($json,'status') === true) && (self::getValue($json,'httpstatus') === 200) ){
				$data = self::getValue($json,'data',array());
				return self::getValue($data,'datas',array());
			}
		}

		return false;	
	}
    
    public static function getDataByJson2($result){
		if(self::isJson($result)){
			$json = json_decode($result);
			if( (self::getValue($json,'status') === true) && (self::getValue($json,'httpstatus') === 200) ){
				return self::getValue($json,'data',array());
			}
		}

		return false;	
	}

	/**
	 * 二维数组按指定列排序
	 *
	 * @param $arr
	 * @param $keys
	 * @param string $type
	 * @return array
	 */
	public static function array_sort($arr, $keys, $type = 'desc') {
		$keysvalue = $new_array = array();
		foreach ($arr as $k => $v) {
			$keysvalue[$k] = U::getValue($v,$keys);
		}
		if ($type == 'asc') {
			asort($keysvalue);
		} else {
			arsort($keysvalue);
		}
		reset($keysvalue);
		foreach ($keysvalue as $k => $v) {
			$new_array[$k] = $arr[$k];
		}

		return $new_array;
	}

	/**
     * 检查字符串是否一个合法的json
     *
     * @param $string
     * @return bool
     */
    public static function isJson($string) {
        json_decode($string);
        return (json_last_error() == JSON_ERROR_NONE);
    }

	/**
     * Isset 封装
     * @param array|object $arr_or_obj
     * @param $key_or_prop
     * @param string $else
     * @return string
     */
    public static function getValue($arr_or_obj, $key_or_prop, $else = ''){
        $result = $else;
        if(isset($arr_or_obj)){
            if(is_array($arr_or_obj)){
                if(isset($arr_or_obj[$key_or_prop])) {
                    $result = $arr_or_obj[$key_or_prop];
                }
            }else if(is_object($arr_or_obj)){
                if (isset($arr_or_obj->$key_or_prop)) {
                    $result = $arr_or_obj->$key_or_prop;
                }
            }
        }

        return $result;
    }


	 /**
	 * 模拟 Post/Get 请求
	 * @param String $url  请求URl
	 * @param Array $info 携带数据
	 * @param int $timeout 超时时间
	 * @return mixed
	 */
    public static function curlRequest($url = '', $info = array(), $timeout = 30) { // 模拟提交数据函数
        $curl = curl_init(); // 启动一个CURL会话
        curl_setopt($curl, CURLOPT_URL, $url); // 要访问的地址
        curl_setopt($curl, CURLOPT_HEADER, 0); // 显示返回的Header区域内容
        curl_setopt($curl, CURLOPT_TIMEOUT, $timeout); // 设置超时限制防止死循环
        if (stripos ( $url, "https://" ) !== FALSE) {
            curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, 0); // 对认证证书来源的检查
            curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, 2); // 从证书中检查SSL加密算法是否存在
        }
        curl_setopt($curl, CURLOPT_USERAGENT, $_SERVER['HTTP_USER_AGENT']); // 模拟用户使用的浏览器
        curl_setopt($curl, CURLOPT_FOLLOWLOCATION, 1); // 使用自动跳转
        curl_setopt($curl, CURLOPT_AUTOREFERER, 1); // 自动设置Referer
        if(!empty($info)) {
            curl_setopt($curl, CURLOPT_POST, 1); // 发送一个常规的Post请求
            curl_setopt($curl, CURLOPT_POSTFIELDS, $info); // Post提交的数据包
        }
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1); // 获取的信息以文件流的形式返回
        $tmpInfo = curl_exec($curl); // 执行操作
        if (curl_errno($curl)) {
            error_log('Errno:' . curl_error($curl)); //捕抓异常
        }
        curl_close($curl); // 关闭CURL会话
        return $tmpInfo; // 返回数据
    }

	/**
	 * 所有数组的笛卡尔积
	 *
	 * @return array
	 */
	public static function combineDika() {
		$data = func_get_args();
		$cnt = count($data);
		$result = array();
		foreach($data[0] as $item) {
			$result[] = array($item);
		}
		for($i = 1; $i < $cnt; $i++) {
			$result = self::combineArray($result,$data[$i]);
		}
		return $result;
	}

	/**
	 * 两个数组的笛卡尔积
	 *
	 * @param $arr1
	 * @param $arr2
	 * @return array
	 */
     public static function combineArray($arr1,$arr2) {
		$result = array();
		foreach ($arr1 as $item1) {
			foreach ($arr2 as $item2) {
				$temp = $item1;
				$temp[] = $item2;
				$result[] = $temp;
			}
		}
		return $result;
	}

    // 终止运行
    public static function end($str = ''){
		echo $str;
    	exit;
    }
}
// 功能类 End


/**
 * 地名处理类
 */
class Z{
	const STATICON_NAMES = '@bjb|北京北|VAP|beijingbei|bjb|0@bjd|北京东|BOP|beijingdong|bjd|1@bji|北京|BJP|beijing|bj|2@bjn|北京南|VNP|beijingnan|bjn|3@bjx|北京西|BXP|beijingxi|bjx|4@gzn|广州南|IZQ|guangzhounan|gzn|5@cqb|重庆北|CUW|chongqingbei|cqb|6@cqi|重庆|CQW|chongqing|cq|7@cqn|重庆南|CRW|chongqingnan|cqn|8@gzd|广州东|GGQ|guangzhoudong|gzd|9@sha|上海|SHH|shanghai|sh|10@shn|上海南|SNH|shanghainan|shn|11@shq|上海虹桥|AOH|shanghaihongqiao|shhq|12@shx|上海西|SXH|shanghaixi|shx|13@tjb|天津北|TBP|tianjinbei|tjb|14@tji|天津|TJP|tianjin|tj|15@tjn|天津南|TIP|tianjinnan|tjn|16@tjx|天津西|TXP|tianjinxi|tjx|17@cch|长春|CCT|changchun|cc|18@ccn|长春南|CET|changchunnan|ccn|19@ccx|长春西|CRT|changchunxi|ccx|20@cdd|成都东|ICW|chengdudong|cdd|21@cdn|成都南|CNW|chengdunan|cdn|22@cdu|成都|CDW|chengdu|cd|23@csh|长沙|CSQ|changsha|cs|24@csn|长沙南|CWQ|changshanan|csn|25@fzh|福州|FZS|fuzhou|fz|26@fzn|福州南|FYS|fuzhounan|fzn|27@gya|贵阳|GIW|guiyang|gy|28@gzh|广州|GZQ|guangzhou|gz|29@gzx|广州西|GXQ|guangzhouxi|gzx|30@heb|哈尔滨|HBB|haerbin|heb|31@hed|哈尔滨东|VBB|haerbindong|hebd|32@hex|哈尔滨西|VAB|haerbinxi|hebx|33@hfe|合肥|HFH|hefei|hf|34@hfx|合肥西|HTH|hefeixi|hfx|35@hhd|呼和浩特东|NDC|huhehaotedong|hhhtd|36@hht|呼和浩特|HHC|huhehaote|hhht|37@hkd|海口东|HMQ|haikoudong|hkd|38@hko|海口|VUQ|haikou|hk|39@hzd|杭州东|HGH|hangzhoudong|hzd|40@hzh|杭州|HZH|hangzhou|hz|41@hzn|杭州南|XHH|hangzhounan|hzn|42@jna|济南|JNK|jinan|jn|43@jnd|济南东|JAK|jinandong|jnd|44@jnx|济南西|JGK|jinanxi|jnx|45@kmi|昆明|KMM|kunming|km|46@kmx|昆明西|KXM|kunmingxi|kmx|47@lsa|拉萨|LSO|lasa|ls|48@lzd|兰州东|LVJ|lanzhoudong|lzd|49@lzh|兰州|LZJ|lanzhou|lz|50@lzx|兰州西|LAJ|lanzhouxi|lzx|51@nch|南昌|NCG|nanchang|nc|52@nji|南京|NJH|nanjing|nj|53@njn|南京南|NKH|nanjingnan|njn|54@nni|南宁|NNZ|nanning|nn|55@sjb|石家庄北|VVP|shijiazhuangbei|sjzb|56@sjz|石家庄|SJP|shijiazhuang|sjz|57@sya|沈阳|SYT|shenyang|sy|58@syb|沈阳北|SBT|shenyangbei|syb|59@syd|沈阳东|SDT|shenyangdong|syd|60@tyb|太原北|TBV|taiyuanbei|tyb|61@tyd|太原东|TDV|taiyuandong|tyd|62@tyu|太原|TYV|taiyuan|ty|63@wha|武汉|WHN|wuhan|wh|64@wjx|王家营西|KNM|wangjiayingxi|wjyx|65@wln|乌鲁木齐南|WMR|wulumuqinan|wlmqn|66@xab|西安北|EAY|xianbei|xab|67@xan|西安|XAY|xian|xa|68@xan|西安南|CAY|xiannan|xan|69@xni|西宁|XNO|xining|xn|70@ych|银川|YIJ|yinchuan|yc|71@zzh|郑州|ZZF|zhengzhou|zz|72@aes|阿尔山|ART|aershan|aes|73@aka|安康|AKY|ankang|ak|74@aks|阿克苏|ASR|akesu|aks|75@alh|阿里河|AHX|alihe|alh|76@alk|阿拉山口|AKR|alashankou|alsk|77@api|安平|APT|anping|ap|78@aqi|安庆|AQH|anqing|aq|79@ash|安顺|ASW|anshun|as|80@ash|鞍山|AST|anshan|as|81@aya|安阳|AYF|anyang|ay|82@ban|北安|BAB|beian|ba|83@bbu|蚌埠|BBH|bengbu|bb|84@bch|白城|BCT|baicheng|bc|85@bha|北海|BHZ|beihai|bh|86@bhe|白河|BEL|baihe|bh|87@bji|白涧|BAP|baijian|bj|88@bji|宝鸡|BJY|baoji|bj|89@bji|滨江|BJB|binjiang|bj|90@bkt|博克图|BKX|boketu|bkt|91@bse|百色|BIZ|baise|bs|92@bss|白山市|HJL|baishanshi|bss|93@bta|北台|BTT|beitai|bt|94@btd|包头东|BDC|baotoudong|btd|95@bto|包头|BTC|baotou|bt|96@bts|北屯市|BXR|beitunshi|bts|97@bxi|本溪|BXT|benxi|bx|98@byb|白云鄂博|BEC|baiyunebo|byeb|99@byx|白银西|BXJ|baiyinxi|byx|100@bzh|亳州|BZH|bozhou|bz|101@cbi|赤壁|CBN|chibi|cb|102@cde|常德|VGQ|changde|cd|103@cde|承德|CDP|chengde|cd|104@cdi|长甸|CDT|changdian|cd|105@cfe|赤峰|CFD|chifeng|cf|106@cli|茶陵|CDG|chaling|cl|107@cna|苍南|CEH|cangnan|cn|108@cpi|昌平|CPP|changping|cp|109@cre|崇仁|CRG|chongren|cr|110@ctu|昌图|CTT|changtu|ct|111@ctz|长汀镇|CDB|changtingzhen|ctz|112@cxi|曹县|CXK|caoxian|cx|113@cxi|楚雄|COM|chuxiong|cx|114@cxt|陈相屯|CXT|chenxiangtun|cxt|115@czb|长治北|CBF|changzhibei|czb|116@czh|长征|CZJ|changzheng|cz|117@czh|池州|IYH|chizhou|cz|118@czh|常州|CZH|changzhou|cz|119@czh|郴州|CZQ|chenzhou|cz|120@czh|长治|CZF|changzhi|cz|121@czh|沧州|COP|cangzhou|cz|122@czu|崇左|CZZ|chongzuo|cz|123@dab|大安北|RNT|daanbei|dab|124@dch|大成|DCT|dacheng|dc|125@ddo|丹东|DUT|dandong|dd|126@dfh|东方红|DFB|dongfanghong|dfh|127@dgd|东莞东|DMQ|dongguandong|dgd|128@dhs|大虎山|DHD|dahushan|dhs|129@dhu|敦煌|DHJ|dunhuang|dh|130@dhu|敦化|DHL|dunhua|dh|131@dhu|德惠|DHT|dehui|dh|132@djc|东京城|DJB|dongjingcheng|djc|133@dji|大涧|DFP|dajian|dj|134@djy|都江堰|DDW|dujiangyan|djy|135@dlb|大连北|DFT|dalianbei|dlb|136@dli|大理|DKM|dali|dl|137@dli|大连|DLT|dalian|dl|138@dna|定南|DNG|dingnan|dn|139@dqi|大庆|DZX|daqing|dq|140@dsh|东胜|DOC|dongsheng|ds|141@dsq|大石桥|DQT|dashiqiao|dsq|142@dto|大同|DTV|datong|dt|143@dyi|东营|DPK|dongying|dy|144@dys|大杨树|DUX|dayangshu|dys|145@dyu|都匀|RYW|duyun|dy|146@dzh|邓州|DOF|dengzhou|dz|147@dzh|达州|RXW|dazhou|dz|148@dzh|德州|DZP|dezhou|dz|149@ejn|额济纳|EJC|ejina|ejn|150@eli|二连|RLC|erlian|el|151@esh|恩施|ESN|enshi|es|152@fdi|福鼎|FES|fuding|fd|153@fld|风陵渡|FLV|fenglingdu|fld|154@fli|涪陵|FLW|fuling|fl|155@flj|富拉尔基|FRX|fulaerji|flej|156@fsb|抚顺北|FET|fushunbei|fsb|157@fsh|佛山|FSQ|foshan|fs|158@fxi|阜新|FXD|fuxin|fx|159@fya|阜阳|FYH|fuyang|fy|160@gem|格尔木|GRO|geermu|gem|161@gha|广汉|GHW|guanghan|gh|162@gji|古交|GJV|gujiao|gj|163@glb|桂林北|GBZ|guilinbei|glb|164@gli|古莲|GRX|gulian|gl|165@gli|桂林|GLZ|guilin|gl|166@gsh|固始|GXN|gushi|gs|167@gsh|广水|GSN|guangshui|gs|168@gta|干塘|GNJ|gantang|gt|169@gyu|广元|GYW|guangyuan|gy|170@gzb|广州北|GBQ|guangzhoubei|gzb|171@gzh|赣州|GZG|ganzhou|gz|172@gzl|公主岭|GLT|gongzhuling|gzl|173@gzn|公主岭南|GBT|gongzhulingnan|gzln|174@han|淮安|AUH|huaian|ha|175@hbe|鹤北|HMB|hebei|hb|176@hbe|淮北|HRH|huaibei|hb|177@hbi|淮滨|HVN|huaibin|hb|178@hbi|河边|HBV|hebian|hb|179@hch|潢川|KCN|huangchuan|hc|180@hch|韩城|HCY|hancheng|hc|181@hda|邯郸|HDP|handan|hd|182@hdz|横道河子|HDB|hengdaohezi|hdhz|183@hga|鹤岗|HGB|hegang|hg|184@hgt|皇姑屯|HTT|huanggutun|hgt|185@hgu|红果|HEM|hongguo|hg|186@hhe|黑河|HJB|heihe|hh|187@hhu|怀化|HHQ|huaihua|hh|188@hko|汉口|HKN|hankou|hk|189@hld|葫芦岛|HLD|huludao|hld|190@hle|海拉尔|HRX|hailaer|hle|191@hll|霍林郭勒|HWD|huolinguole|hlgl|192@hlu|海伦|HLB|hailun|hl|193@hma|侯马|HMV|houma|hm|194@hmi|哈密|HMR|hami|hm|195@hna|淮南|HAH|huainan|hn|196@hna|桦南|HNB|huanan|hn|197@hnx|海宁西|EUH|hainingxi|hnx|198@hqi|鹤庆|HQM|heqing|hq|199@hrb|怀柔北|HBP|huairoubei|hrb|200@hro|怀柔|HRP|huairou|hr|201@hsd|黄石东|OSN|huangshidong|hsd|202@hsh|华山|HSY|huashan|hs|203@hsh|黄石|HSN|huangshi|hs|204@hsh|黄山|HKH|huangshan|hs|205@hsh|衡水|HSP|hengshui|hs|206@hya|衡阳|HYQ|hengyang|hy|207@hze|菏泽|HIK|heze|hz|208@hzh|贺州|HXZ|hezhou|hz|209@hzh|汉中|HOY|hanzhong|hz|210@hzh|惠州|HCQ|huizhou|hz|211@jan|吉安|VAG|jian|ja|212@jan|集安|JAL|jian|ja|213@jbc|江边村|JBG|jiangbiancun|jbc|214@jch|晋城|JCF|jincheng|jc|215@jcj|金城江|JJZ|jinchengjiang|jcj|216@jdz|景德镇|JCG|jingdezhen|jdz|217@jfe|嘉峰|JFF|jiafeng|jf|218@jgq|加格达奇|JGX|jiagedaqi|jgdq|219@jgs|井冈山|JGG|jinggangshan|jgs|220@jhe|蛟河|JHL|jiaohe|jh|221@jhn|金华南|RNH|jinhuanan|jhn|222@jhu|金华|JBH|jinhua|jh|223@jji|九江|JJG|jiujiang|jj|224@jli|吉林|JLL|jilin|jl|225@jme|荆门|JMN|jingmen|jm|226@jms|佳木斯|JMB|jiamusi|jms|227@jni|济宁|JIK|jining|jn|228@jnn|集宁南|JAC|jiningnan|jnn|229@jqu|酒泉|JQJ|jiuquan|jq|230@jsh|江山|JUH|jiangshan|js|231@jsh|吉首|JIQ|jishou|js|232@jta|九台|JTL|jiutai|jt|233@jts|镜铁山|JVJ|jingtieshan|jts|234@jxi|鸡西|JXB|jixi|jx|235@jxi|蓟县|JKP|jixian|jx|236@jxx|绩溪县|JRH|jixixian|jxx|237@jyg|嘉峪关|JGJ|jiayuguan|jyg|238@jyo|江油|JFW|jiangyou|jy|239@jzh|锦州|JZD|jinzhou|jz|240@jzh|金州|JZT|jinzhou|jz|241@kel|库尔勒|KLR|kuerle|kel|242@kfe|开封|KFF|kaifeng|kf|243@kla|岢岚|KLV|kelan|kl|244@kli|凯里|KLW|kaili|kl|245@ksh|喀什|KSR|kashi|ks|246@ksn|昆山南|KNH|kunshannan|ksn|247@ktu|奎屯|KTR|kuitun|kt|248@kyu|开原|KYT|kaiyuan|ky|249@lan|六安|UAH|luan|la|250@lba|灵宝|LBF|lingbao|lb|251@lcg|芦潮港|UCH|luchaogang|lcg|252@lch|隆昌|LCW|longchang|lc|253@lch|陆川|LKZ|luchuan|lc|254@lch|利川|LCN|lichuan|lc|255@lch|临川|LCG|linchuan|lc|256@lch|潞城|UTP|lucheng|lc|257@lda|鹿道|LDL|ludao|ld|258@ldi|娄底|LDQ|loudi|ld|259@lfe|临汾|LFV|linfen|lf|260@lgz|良各庄|LGP|lianggezhuang|lgz|261@lhe|临河|LHC|linhe|lh|262@lhe|漯河|LON|luohe|lh|263@lhu|绿化|LWJ|lvhua|lh|264@lhu|隆化|UHP|longhua|lh|265@lji|丽江|LHM|lijiang|lj|266@lji|临江|LQL|linjiang|lj|267@lji|龙井|LJL|longjing|lj|268@lli|吕梁|LHV|lvliang|ll|269@lli|醴陵|LLG|liling|ll|270@lln|柳林南|LKV|liulinnan|lln|271@lpi|滦平|UPP|luanping|lp|272@lps|六盘水|UMW|liupanshui|lps|273@lqi|灵丘|LVV|lingqiu|lq|274@lsh|旅顺|LST|lvshun|ls|275@lxi|陇西|LXJ|longxi|lx|276@lxi|澧县|LEQ|lixian|lx|277@lxi|兰溪|LWH|lanxi|lx|278@lxi|临西|UEP|linxi|lx|279@lya|龙岩|LYS|longyan|ly|280@lya|耒阳|LYQ|leiyang|ly|281@lya|洛阳|LYF|luoyang|ly|282@lyd|洛阳东|LDF|luoyangdong|lyd|283@lyd|连云港东|UKH|lianyungangdong|lygd|284@lyi|临沂|LVK|linyi|ly|285@lym|洛阳龙门|LLF|luoyanglongmen|lylm|286@lyu|柳园|DHR|liuyuan|ly|287@lyu|凌源|LYD|lingyuan|ly|288@lyu|辽源|LYL|liaoyuan|ly|289@lzh|立志|LZX|lizhi|lz|290@lzh|柳州|LZZ|liuzhou|lz|291@lzh|辽中|LZD|liaozhong|lz|292@mch|麻城|MCN|macheng|mc|293@mdh|免渡河|MDX|mianduhe|mdh|294@mdj|牡丹江|MDB|mudanjiang|mdj|295@meg|莫尔道嘎|MRX|moerdaoga|medg|296@mgu|满归|MHX|mangui|mg|297@mgu|明光|MGH|mingguang|mg|298@mhe|漠河|MVX|mohe|mh|299@mmd|茂名东|MDQ|maomingdong|mmd|300@mmi|茂名|MMZ|maoming|mm|301@msh|密山|MSB|mishan|ms|302@msj|马三家|MJT|masanjia|msj|303@mwe|麻尾|VAW|mawei|mw|304@mya|绵阳|MYW|mianyang|my|305@mzh|梅州|MOQ|meizhou|mz|306@mzl|满洲里|MLX|manzhouli|mzl|307@nbd|宁波东|NVH|ningbodong|nbd|308@nbo|宁波|NGH|ningbo|nb|309@nch|南岔|NCB|nancha|nc|310@nch|南充|NCW|nanchong|nc|311@nda|南丹|NDZ|nandan|nd|312@ndm|南大庙|NMP|nandamiao|ndm|313@nfe|南芬|NFT|nanfen|nf|314@nhe|讷河|NHX|nehe|nh|315@nji|嫩江|NGX|nenjiang|nj|316@nji|内江|NJW|neijiang|nj|317@npi|南平|NPS|nanping|np|318@nto|南通|NUH|nantong|nt|319@nya|南阳|NFF|nanyang|ny|320@nzs|碾子山|NZX|nianzishan|nzs|321@pds|平顶山|PEN|pingdingshan|pds|322@pji|盘锦|PVD|panjin|pj|323@pli|平凉|PIJ|pingliang|pl|324@pln|平凉南|POJ|pingliangnan|pln|325@pqu|平泉|PQP|pingquan|pq|326@psh|坪石|PSQ|pingshi|ps|327@pxi|萍乡|PXG|pingxiang|px|328@pxi|凭祥|PXZ|pingxiang|px|329@pxx|郫县西|PCW|pixianxi|pxx|330@pzh|攀枝花|PRW|panzhihua|pzh|331@qch|蕲春|QRN|qichun|qc|332@qcs|青城山|QSW|qingchengshan|qcs|333@qda|青岛|QDK|qingdao|qd|334@qhc|清河城|QYP|qinghecheng|qhc|335@qji|黔江|QNW|qianjiang|qj|336@qji|曲靖|QJM|qujing|qj|337@qjz|前进镇|QEB|qianjinzhen|qjz|338@qqe|齐齐哈尔|QHX|qiqihaer|qqhe|339@qth|七台河|QTB|qitaihe|qth|340@qxi|沁县|QVV|qinxian|qx|341@qzd|泉州东|QRS|quanzhoudong|qzd|342@qzh|泉州|QYS|quanzhou|qz|343@qzh|衢州|QEH|quzhou|qz|344@ran|融安|RAZ|rongan|ra|345@rjg|汝箕沟|RQJ|rujigou|rqg|346@rji|瑞金|RJG|ruijin|rj|347@rzh|日照|RZK|rizhao|rz|348@scp|双城堡|SCB|shuangchengpu|scb|349@sfh|绥芬河|SFB|suifenhe|sfh|350@sgd|韶关东|SGQ|shaoguandong|sgd|351@shg|山海关|SHD|shanhaiguan|shg|352@shu|绥化|SHB|suihua|sh|353@sjf|三间房|SFX|sanjianfang|sjf|354@sjt|苏家屯|SXT|sujiatun|sjt|355@sla|舒兰|SLL|shulan|sl|356@smi|三明|SMS|sanming|sm|357@smu|神木|OMY|shenmu|sm|358@smx|三门峡|SMF|sanmenxia|smx|359@sna|商南|ONY|shangnan|sn|360@sni|遂宁|NIW|suining|sn|361@spi|四平|SPT|siping|sp|362@sqi|商丘|SQF|shangqiu|sq|363@sra|上饶|SRG|shangrao|sr|364@ssh|韶山|SSQ|shaoshan|ss|365@sso|宿松|OAH|susong|ss|366@sto|汕头|OTQ|shantou|st|367@swu|邵武|SWS|shaowu|sw|368@sxi|涉县|OEP|shexian|sx|369@sya|三亚|SEQ|sanya|sy|370@sya|邵阳|SYQ|shaoyang|sy|371@sya|十堰|SNN|shiyan|sy|372@sys|双鸭山|SSB|shuangyashan|sys|373@syu|松原|VYT|songyuan|sy|374@szh|深圳|SZQ|shenzhen|sz|375@szh|苏州|SZH|suzhou|sz|376@szh|随州|SZN|suizhou|sz|377@szh|宿州|OXH|suzhou|sz|378@szh|朔州|SUV|shuozhou|sz|379@szx|深圳西|OSQ|shenzhenxi|szx|380@tba|塘豹|TBQ|tangbao|tb|381@teq|塔尔气|TVX|taerqi|teq|382@tgu|潼关|TGY|tongguan|tg|383@tgu|塘沽|TGP|tanggu|tg|384@the|塔河|TXX|tahe|th|385@thu|通化|THL|tonghua|th|386@tla|泰来|TLX|tailai|tl|387@tlf|吐鲁番|TFR|tulufan|tlf|388@tli|通辽|TLD|tongliao|tl|389@tli|铁岭|TLT|tieling|tl|390@tlz|陶赖昭|TPT|taolaizhao|tlz|391@tme|图们|TML|tumen|tm|392@tre|铜仁|RDQ|tongren|tr|393@tsb|唐山北|FUP|tangshanbei|tsb|394@tsf|田师府|TFT|tianshifu|tsf|395@tsh|泰山|TAK|taishan|ts|396@tsh|唐山|TSP|tangshan|ts|397@tsh|天水|TSJ|tianshui|ts|398@typ|通远堡|TYT|tongyuanpu|tyb|399@tys|太阳升|TQT|taiyangsheng|tys|400@tzh|泰州|UTH|taizhou|tz|401@tzi|桐梓|TZW|tongzi|tz|402@tzx|通州西|TAP|tongzhouxi|tzx|403@wch|五常|WCB|wuchang|wc|404@wch|武昌|WCN|wuchang|wc|405@wfd|瓦房店|WDT|wafangdian|wfd|406@wha|威海|WKK|weihai|wh|407@whu|芜湖|WHH|wuhu|wh|408@whx|乌海西|WXC|wuhaixi|whx|409@wjt|吴家屯|WJT|wujiatun|wjt|410@wlo|武隆|WLW|wulong|wl|411@wlt|乌兰浩特|WWT|wulanhaote|wlht|412@wna|渭南|WNY|weinan|wn|413@wsh|威舍|WSM|weishe|ws|414@wts|歪头山|WIT|waitoushan|wts|415@wwe|武威|WUJ|wuwei|ww|416@wwn|武威南|WWJ|wuweinan|wwn|417@wxi|无锡|WXH|wuxi|wx|418@wxi|乌西|WXR|wuxi|wx|419@wyl|乌伊岭|WPB|wuyiling|wyl|420@wys|武夷山|WAS|wuyishan|wys|421@wyu|万源|WYY|wanyuan|wy|422@wzh|万州|WYW|wanzhou|wz|423@wzh|梧州|WZZ|wuzhou|wz|424@wzh|温州|RZH|wenzhou|wz|425@wzn|温州南|VRH|wenzhounan|wzn|426@xch|西昌|ECW|xichang|xc|427@xch|许昌|XCF|xuchang|xc|428@xcn|西昌南|ENW|xichangnan|xcn|429@xfa|香坊|XFB|xiangfang|xf|430@xga|轩岗|XGV|xuangang|xg|431@xgu|兴国|EUG|xingguo|xg|432@xha|宣汉|XHY|xuanhan|xh|433@xhu|新会|EFQ|xinhui|xh|434@xhu|新晃|XLQ|xinhuang|xh|435@xlt|锡林浩特|XTC|xilinhaote|xlht|436@xlx|兴隆县|EXP|xinglongxian|xlx|437@xmb|厦门北|XKS|xiamenbei|xmb|438@xme|厦门|XMS|xiamen|xm|439@xmq|厦门高崎|XBS|xiamengaoqi|xmgq|440@xsh|秀山|ETW|xiushan|xs|441@xsh|小市|XST|xiaoshi|xs|442@xta|向塘|XTG|xiangtang|xt|443@xwe|宣威|XWM|xuanwei|xw|444@xxi|新乡|XXF|xinxiang|xx|445@xya|信阳|XUN|xinyang|xy|446@xya|咸阳|XYY|xianyang|xy|447@xya|襄阳|XFN|xiangyang|xy|448@xyc|熊岳城|XYT|xiongyuecheng|xyc|449@xyi|兴义|XRZ|xingyi|xy|450@xyi|新沂|VIH|xinyi|xy|451@xyu|新余|XUG|xinyu|xy|452@xzh|徐州|XCH|xuzhou|xz|453@yan|延安|YWY|yanan|ya|454@ybi|宜宾|YBW|yibin|yb|455@ybn|亚布力南|YWB|yabulinan|ybln|456@ybs|叶柏寿|YBD|yebaishou|ybs|457@ycd|宜昌东|HAN|yichangdong|ycd|458@ych|永川|YCW|yongchuan|yc|459@ych|宜昌|YCN|yichang|yc|460@ych|盐城|AFH|yancheng|yc|461@ych|运城|YNV|yuncheng|yc|462@ych|伊春|YCB|yichun|yc|463@yci|榆次|YCV|yuci|yc|464@ycu|杨村|YBP|yangcun|yc|465@ycx|宜春西|YCG|yichunxi|ycx|466@yes|伊尔施|YET|yiershi|yes|467@yga|燕岗|YGW|yangang|yg|468@yji|永济|YIV|yongji|yj|469@yji|延吉|YJL|yanji|yj|470@yko|营口|YKT|yingkou|yk|471@yks|牙克石|YKX|yakeshi|yks|472@yli|阎良|YNY|yanliang|yl|473@yli|玉林|YLZ|yulin|yl|474@yli|榆林|ALY|yulin|yl|475@ymp|一面坡|YPB|yimianpo|ymp|476@yni|伊宁|YMR|yining|yn|477@ypg|阳平关|YAY|yangpingguan|ypg|478@ypi|玉屏|YZW|yuping|yp|479@ypi|原平|YPV|yuanping|yp|480@yqi|延庆|YNP|yanqing|yq|481@yqq|阳泉曲|YYV|yangquanqu|yqq|482@yqu|玉泉|YQB|yuquan|yq|483@yqu|阳泉|AQP|yangquan|yq|484@ysh|玉山|YNG|yushan|ys|485@ysh|营山|NUW|yingshan|ys|486@ysh|燕山|AOP|yanshan|ys|487@ysh|榆树|YRT|yushu|ys|488@yta|鹰潭|YTG|yingtan|yt|489@yta|烟台|YAK|yantai|yt|490@yth|伊图里河|YEX|yitulihe|ytlh|491@ytx|玉田县|ATP|yutianxian|ytx|492@ywu|义乌|YWH|yiwu|yw|493@yxi|阳新|YON|yangxin|yx|494@yxi|义县|YXD|yixian|yx|495@yya|益阳|AEQ|yiyang|yy|496@yya|岳阳|YYQ|yueyang|yy|497@yzh|永州|AOQ|yongzhou|yz|498@yzh|扬州|YLH|yangzhou|yz|499@zbo|淄博|ZBK|zibo|zb|500@zcd|镇城底|ZDV|zhenchengdi|zcd|501@zgo|自贡|ZGW|zigong|zg|502@zha|珠海|ZHQ|zhuhai|zh|503@zhb|珠海北|ZIQ|zhuhaibei|zhb|504@zji|湛江|ZJZ|zhanjiang|zj|505@zji|镇江|ZJH|zhenjiang|zj|506@zjj|张家界|DIQ|zhangjiajie|zjj|507@zjk|张家口|ZKP|zhangjiakou|zjk|508@zjn|张家口南|ZMP|zhangjiakounan|zjkn|509@zko|周口|ZKN|zhoukou|zk|510@zlm|哲里木|ZLC|zhelimu|zlm|511@zlt|扎兰屯|ZTX|zhalantun|zlt|512@zmd|驻马店|ZDN|zhumadian|zmd|513@zqi|肇庆|ZVQ|zhaoqing|zq|514@zsz|周水子|ZIT|zhoushuizi|zsz|515@zto|昭通|ZDW|zhaotong|zt|516@zwe|中卫|ZWJ|zhongwei|zw|517@zya|资阳|ZYW|ziyang|zy|518@zyi|遵义|ZIW|zunyi|zy|519@zzh|枣庄|ZEK|zaozhuang|zz|520@zzh|资中|ZZW|zizhong|zz|521@zzh|株洲|ZZQ|zhuzhou|zz|522@zzx|枣庄西|ZFK|zaozhuangxi|zzx|523@aax|昂昂溪|AAX|angangxi|aax|524@ach|阿城|ACB|acheng|ac|525@ada|安达|ADX|anda|ad|526@ade|安德|ARW|ande|ad|527@adi|安定|ADP|anding|ad|528@agu|安广|AGT|anguang|ag|529@ahe|艾河|AHP|aihe|ah|530@ahu|安化|PKQ|anhua|ah|531@ajc|艾家村|AJJ|aijiacun|ajc|532@aji|鳌江|ARH|aojiang|aj|533@aji|安家|AJB|anjia|aj|534@aji|阿金|AJD|ajin|aj|535@akt|阿克陶|AER|aketao|akt|536@aky|安口窑|AYY|ankouyao|aky|537@alg|敖力布告|ALD|aolibugao|albg|538@alo|安龙|AUZ|anlong|al|539@als|阿龙山|ASX|alongshan|als|540@alu|安陆|ALN|anlu|al|541@ame|阿木尔|JTX|amuer|ame|542@anz|阿南庄|AZM|ananzhuang|anz|543@aqx|安庆西|APH|anqingxi|aqx|544@asx|鞍山西|AXT|anshanxi|asx|545@ata|安塘|ATV|antang|at|546@atb|安亭北|ASH|antingbei|atb|547@ats|阿图什|ATR|atushi|ats|548@atu|安图|ATL|antu|at|549@axi|安溪|AXS|anxi|ax|550@bao|博鳌|BWQ|boao|ba|551@bbe|北碚|BPW|beibei|bb|552@bbg|白壁关|BGV|baibiguan|bbg|553@bbn|蚌埠南|BMH|bengbunan|bbn|554@bch|巴楚|BCR|bachu|bc|555@bch|板城|BUP|bancheng|bc|556@bdh|北戴河|BEP|beidaihe|bdh|557@bdi|保定|BDP|baoding|bd|558@bdi|宝坻|BPP|baodi|bd|559@bdl|八达岭|ILP|badaling|bdl|560@bdo|巴东|BNN|badong|bd|561@bgu|柏果|BGM|baiguo|bg|562@bha|布海|BUT|buhai|bh|563@bhd|白河东|BIY|baihedong|bhd|564@bho|贲红|BVC|benhong|bh|565@bhs|宝华山|BWH|baohuashan|bhs|566@bhx|白河县|BEY|baihexian|bhx|567@bjg|白芨沟|BJJ|baijigou|bjg|568@bjg|碧鸡关|BJM|bijiguan|bjg|569@bji|北滘|IBQ|beijiao|b|570@bji|碧江|BLQ|bijiang|bj|571@bjp|白鸡坡|BBM|baijipo|bjp|572@bjs|笔架山|BSB|bijiashan|bjs|573@bjt|八角台|BTD|bajiaotai|bjt|574@bka|保康|BKD|baokang|bk|575@bkp|白奎堡|BKB|baikuipu|bkb|576@bla|白狼|BAT|bailang|bl|577@bla|百浪|BRZ|bailang|bl|578@ble|博乐|BOR|bole|bl|579@blg|宝拉格|BQC|baolage|blg|580@bli|巴林|BLX|balin|bl|581@bli|宝林|BNB|baolin|bl|582@bli|北流|BOZ|beiliu|bl|583@bli|勃利|BLB|boli|bl|584@blk|布列开|BLR|buliekai|blk|585@bls|宝龙山|BND|baolongshan|bls|586@blx|百里峡|AAP|bailixia|blx|587@bmc|八面城|BMD|bamiancheng|bmc|588@bmq|班猫箐|BNM|banmaoqing|bmj|589@bmt|八面通|BMB|bamiantong|bmt|590@bmz|北马圈子|BRP|beimaquanzi|bmqz|591@bpn|北票南|RPD|beipiaonan|bpn|592@bqi|白旗|BQP|baiqi|bq|593@bql|宝泉岭|BQB|baoquanling|bql|594@bqu|白泉|BQL|baiquan|bq|595@bsh|白沙|BSW|baisha|bs|596@bsh|巴山|BAY|bashan|bs|597@bsj|白水江|BSY|baishuijiang|bsj|598@bsp|白沙坡|BPM|baishapo|bsp|599@bss|白石山|BAL|baishishan|bss|600@bsz|白水镇|BUM|baishuizhen|bsz|601@bti|坂田|BTQ|bantian|bt|602@bto|泊头|BZP|botou|bt|603@btu|北屯|BYP|beitun|bt|604@bxh|本溪湖|BHT|benxihu|bxh|605@bxi|博兴|BXK|boxing|bx|606@bxt|八仙筒|VXD|baxiantong|bxt|607@byg|白音察干|BYC|baiyinchagan|bycg|608@byh|背荫河|BYB|beiyinhe|byh|609@byi|北营|BIV|beiying|by|610@byl|巴彦高勒|BAC|bayangaole|bygl|611@byl|白音他拉|BID|baiyintala|bytl|612@byq|鲅鱼圈|BYT|bayuquan|byq|613@bys|白银市|BNJ|baiyinshi|bys|614@bys|白音胡硕|BCD|baiyinhushuo|byhs|615@bzh|巴中|IEW|bazhong|bz|616@bzh|霸州|RMP|bazhou|bz|617@bzh|北宅|BVP|beizhai|bz|618@cbb|赤壁北|CIN|chibibei|cbb|619@cbg|查布嘎|CBC|chabuga|cbg|620@cch|长城|CEJ|changcheng|cc|621@cch|长冲|CCM|changchong|cc|622@cdd|承德东|CCP|chengdedong|cdd|623@cfx|赤峰西|CID|chifengxi|cfx|624@cga|嵯岗|CAX|cuogang|cg|625@cga|柴岗|CGT|chaigang|cg|626@cge|长葛|CEF|changge|cg|627@cgp|柴沟堡|CGV|chaigoupu|cgb|628@cgu|城固|CGY|chenggu|cg|629@cgy|陈官营|CAJ|chenguanying|cgy|630@cgz|成高子|CZB|chenggaozi|cgz|631@cha|草海|WBW|caohai|ch|632@che|柴河|CHB|chaihe|ch|633@che|册亨|CHZ|ceheng|ch|634@chk|草河口|CKT|caohekou|chk|635@chk|崔黄口|CHP|cuihuangkou|chk|636@chu|巢湖|CIH|chaohu|ch|637@cjg|蔡家沟|CJT|caijiagou|cjg|638@cjh|成吉思汗|CJX|chengjisihan|cjsh|639@cji|岔江|CAM|chajiang|cj|640@cjp|蔡家坡|CJY|caijiapo|cjp|641@cle|昌乐|CLK|changle|cl|642@clg|超梁沟|CYP|chaolianggou|clg|643@cli|慈利|CUQ|cili|cl|644@cli|昌黎|CLP|changli|cl|645@clz|长岭子|CLT|changlingzi|clz|646@cmi|晨明|CMB|chenming|cm|647@cno|长农|CNJ|changnong|cn|648@cpb|昌平北|VBP|changpingbei|cpb|649@cpi|常平|DAQ|changping|cp|650@cpl|长坡岭|CPM|changpoling|cpl|651@cqi|辰清|CQB|chenqing|cq|652@csh|蔡山|CON|caishan|cs|653@csh|楚山|CSB|chushan|cs|654@csh|长寿|EFW|changshou|cs|655@csh|磁山|CSP|cishan|cs|656@csh|苍石|CST|cangshi|cs|657@csh|草市|CSL|caoshi|cs|658@csq|察素齐|CSC|chasuqi|csq|659@cst|长山屯|CVT|changshantun|cst|660@cti|长汀|CES|changting|ct|661@ctx|昌图西|CPT|changtuxi|ctx|662@cwa|春湾|CQQ|chunwan|cw|663@cxi|磁县|CIP|cixian|cx|664@cxi|岑溪|CNZ|cenxi|cx|665@cxi|辰溪|CXQ|chenxi|cx|666@cxi|磁西|CRP|cixi|cx|667@cxn|长兴南|CFH|changxingnan|cxn|668@cya|磁窑|CYK|ciyao|cy|669@cya|朝阳|CYD|chaoyang|cy|670@cya|春阳|CAL|chunyang|cy|671@cya|城阳|CEK|chengyang|cy|672@cyc|创业村|CEX|chuangyecun|cyc|673@cyc|朝阳川|CYL|chaoyangchuan|cyc|674@cyd|朝阳地|CDD|chaoyangdi|cyd|675@cyu|长垣|CYF|changyuan|cy|676@cyz|朝阳镇|CZL|chaoyangzhen|cyz|677@czb|滁州北|CUH|chuzhoubei|czb|678@czb|常州北|ESH|changzhoubei|czb|679@czh|滁州|CXH|chuzhou|cz|680@czh|潮州|CKQ|chaozhou|cz|681@czh|常庄|CVK|changzhuang|cz|682@czl|曹子里|CFP|caozili|czl|683@czw|车转湾|CWM|chezhuanwan|czw|684@czx|郴州西|ICQ|chenzhouxi|czx|685@czx|沧州西|CBP|cangzhouxi|czx|686@dan|德安|DAG|dean|da|687@dan|大安|RAT|daan|da|688@dba|大坝|DBJ|daba|db|689@dba|大板|DBC|daban|db|690@dba|大巴|DBD|daba|db|691@dba|到保|RBT|daobao|db|692@dbi|定边|DYJ|dingbian|db|693@dbj|东边井|DBB|dongbianjing|dbj|694@dbs|德伯斯|RDT|debosi|dbs|695@dcg|打柴沟|DGJ|dachaigou|dcg|696@dch|德昌|DVW|dechang|dc|697@dda|滴道|DDB|didao|dd|698@ddg|大磴沟|DKJ|dadenggou|ddg|699@ded|刀尔登|DRD|daoerdeng|ded|700@dee|得耳布尔|DRX|deerbuer|debe|701@dfa|东方|UFQ|dongfang|df|702@dfe|丹凤|DGY|danfeng|df|703@dfe|东丰|DIL|dongfeng|df|704@dge|都格|DMM|duge|dg|705@dgt|大官屯|DTT|daguantun|dgt|706@dgu|大关|RGW|daguan|dg|707@dgu|东光|DGP|dongguang|dg|708@dha|东海|DHB|donghai|dh|709@dhc|大灰厂|DHP|dahuichang|dhc|710@dhq|大红旗|DQD|dahongqi|dhq|711@dht|大禾塘|SOQ|shaodong|sd|712@dhx|东海县|DQH|donghaixian|dhx|713@dhx|德惠西|DXT|dehuixi|dhx|714@djg|达家沟|DJT|dajiagou|djg|715@dji|东津|DKB|dongjin|dj|716@dji|杜家|DJL|dujia|dj|717@dkt|大口屯|DKP|dakoutun|dkt|718@dla|东来|RVD|donglai|dl|719@dlh|德令哈|DHO|delingha|dlh|720@dlh|大陆号|DLC|daluhao|dlh|721@dli|带岭|DLB|dailing|dl|722@dli|大林|DLD|dalin|dl|723@dlq|达拉特旗|DIC|dalateqi|dltq|724@dlt|独立屯|DTX|dulitun|dlt|725@dlu|豆罗|DLV|douluo|dl|726@dlx|达拉特西|DNC|dalatexi|dltx|727@dmc|东明村|DMD|dongmingcun|dmc|728@dmh|洞庙河|DEP|dongmiaohe|dmh|729@dmx|东明县|DNF|dongmingxian|dmx|730@dni|大拟|DNZ|dani|dn|731@dpf|大平房|DPD|dapingfang|dpf|732@dps|大盘石|RPP|dapanshi|dps|733@dpu|大埔|DPI|dapu|dp|734@dpu|大堡|DVT|dapu|db|735@dqd|大庆东|LFX|daqingdong|dqd|736@dqh|大其拉哈|DQX|daqilaha|dqlh|737@dqi|道清|DML|daoqing|dq|738@dqs|对青山|DQB|duiqingshan|dqs|739@dqx|德清西|MOH|deqingxi|dqx|740@dqx|大庆西|RHX|daqingxi|dqx|741@dsh|东升|DRQ|dongsheng|ds|742@dsh|独山|RWW|dushan|ds|743@dsh|砀山|DKH|dangshan|ds|744@dsh|登沙河|DWT|dengshahe|dsh|745@dsp|读书铺|DPM|dushupu|dsp|746@dst|大石头|DSL|dashitou|dst|747@dsx|东胜西|DYC|dongshengxi|dsx|748@dsz|大石寨|RZT|dashizhai|dsz|749@dta|东台|DBH|dongtai|dt|750@dta|定陶|DQK|dingtao|dt|751@dta|灯塔|DGT|dengta|dt|752@dtb|大田边|DBM|datianbian|dtb|753@dth|东通化|DTL|dongtonghua|dth|754@dtu|丹徒|RUH|dantu|dt|755@dtu|大屯|DNT|datun|dt|756@dwa|东湾|DRJ|dongwan|dw|757@dwk|大武口|DFJ|dawukou|dwk|758@dwp|低窝铺|DWJ|diwopu|dwp|759@dwt|大王滩|DZZ|dawangtan|dwt|760@dwz|大湾子|DFM|dawanzi|dwz|761@dxg|大兴沟|DXL|daxinggou|dxg|762@dxi|大兴|DXX|daxing|dx|763@dxi|定西|DSJ|dingxi|dx|764@dxi|甸心|DXM|dianxin|dx|765@dxi|东乡|DXG|dongxiang|dx|766@dxi|代县|DKV|daixian|dx|767@dxi|定襄|DXV|dingxiang|dx|768@dxu|东戌|RXP|dongxu|dx|769@dxz|东辛庄|DXD|dongxinzhuang|dxz|770@dya|德阳|DYW|deyang|dy|771@dya|丹阳|DYH|danyang|dy|772@dya|大雁|DYX|dayan|dy|773@dya|当阳|DYN|dangyang|dy|774@dyb|丹阳北|EXH|danyangbei|dyb|775@dyd|大英东|IAW|dayingdong|dyd|776@dyd|东淤地|DBV|dongyudi|dyd|777@dyi|大营|DYV|daying|dy|778@dyu|定远|EWH|dingyuan|dy|779@dyu|岱岳|RYV|daiyue|dy|780@dyu|大元|DYZ|dayuan|dy|781@dyz|大营镇|DJP|dayingzhen|dyz|782@dyz|大营子|DZD|dayingzi|dyz|783@dzc|大战场|DTJ|dazhanchang|dzc|784@dzd|德州东|DIP|dezhoudong|dzd|785@dzh|低庄|DVQ|dizhuang|dz|786@dzh|东镇|DNV|dongzhen|dz|787@dzh|道州|DFZ|daozhou|dz|788@dzh|东至|DCH|dongzhi|dz|789@dzh|东庄|DZV|dongzhuang|dz|790@dzh|兑镇|DWV|duizhen|dz|791@dzh|豆庄|ROP|douzhuang|dz|792@dzh|定州|DXP|dingzhou|dz|793@dzy|大竹园|DZY|dazhuyuan|dzy|794@dzz|大杖子|DAP|dazhangzi|dzz|795@dzz|豆张庄|RZP|douzhangzhuang|dzz|796@ebi|峨边|EBW|ebian|eb|797@edm|二道沟门|RDP|erdaogoumen|edgm|798@edw|二道湾|RDX|erdaowan|edw|799@ees|鄂尔多斯|EEC|eerduosi|eeds|800@elo|二龙|RLD|erlong|el|801@elt|二龙山屯|ELA|erlongshantun|elst|802@eme|峨眉|EMW|emei|em|803@emh|二密河|RML|ermihe|emh|804@eyi|二营|RYJ|erying|ey|805@ezh|鄂州|ECN|ezhou|ez|806@fan|福安|FAS|fuan|fa|807@fch|丰城|FCG|fengcheng|fc|808@fcn|丰城南|FNG|fengchengnan|fcn|809@fdo|肥东|FIH|feidong|fd|810@fer|发耳|FEM|faer|fe|811@fha|富海|FHX|fuhai|fh|812@fha|福海|FHR|fuhai|fh|813@fhc|凤凰城|FHT|fenghuangcheng|fhc|814@fhe|汾河|FEV|fenhe|fh|815@fhu|奉化|FHH|fenghua|fh|816@fji|富锦|FIB|fujin|fj|817@fjt|范家屯|FTT|fanjiatun|fjt|818@flq|福利区|FLJ|fuliqu|flq|819@flt|福利屯|FTB|fulitun|flt|820@flz|丰乐镇|FZB|fenglezhen|flz|821@fna|阜南|FNH|funan|fn|822@fni|阜宁|AKH|funing|fn|823@fni|抚宁|FNP|funing|fn|824@fqi|福清|FQS|fuqing|fq|825@fqu|福泉|VMW|fuquan|fq|826@fsc|丰水村|FSJ|fengshuicun|fsc|827@fsh|丰顺|FUQ|fengshun|fs|828@fsh|繁峙|FSV|fanshi|fs|829@fsh|抚顺|FST|fushun|fs|830@fsk|福山口|FKP|fushankou|fsk|831@fsu|扶绥|FSZ|fusui|fs|832@ftu|冯屯|FTX|fengtun|ft|833@fty|浮图峪|FYP|futuyu|fty|834@fxd|富县东|FDY|fuxiandong|fxd|835@fxi|凤县|FXY|fengxian|fx|836@fxi|富县|FEY|fuxian|fx|837@fxi|费县|FXK|feixian|fx|838@fya|凤阳|FUH|fengyang|fy|839@fya|汾阳|FAV|fenyang|fy|840@fyb|扶余北|FBT|fuyubei|fyb|841@fyi|分宜|FYG|fenyi|fy|842@fyu|富源|FYM|fuyuan|fy|843@fyu|扶余|FYT|fuyu|fy|844@fyu|富裕|FYX|fuyu|fy|845@fzb|抚州北|FBG|fuzhoubei|fzb|846@fzh|凤州|FZY|fengzhou|fz|847@fzh|丰镇|FZC|fengzhen|fz|848@fzh|范镇|VZK|fanzhen|fz|849@gan|固安|GFP|guan|ga|850@gan|广安|VJW|guangan|ga|851@gbd|高碑店|GBP|gaobeidian|gbd|852@gbz|沟帮子|GBD|goubangzi|gbz|853@gcd|甘草店|GDJ|gancaodian|gcd|854@gch|谷城|GCN|gucheng|gc|855@gch|藁城|GEP|gaocheng|gc|856@gcu|高村|GCV|gaocun|gc|857@gcz|古城镇|GZB|guchengzhen|gcz|858@gde|广德|GRH|guangde|gd|859@gdi|贵定|GTW|guiding|gd|860@gdn|贵定南|IDW|guidingnan|gdn|861@gdo|古东|GDV|gudong|gd|862@gga|贵港|GGZ|guigang|gg|863@gga|官高|GVP|guangao|gg|864@ggm|葛根庙|GGT|gegenmiao|ggm|865@ggo|干沟|GGL|gangou|gg|866@ggu|甘谷|GGJ|gangu|gg|867@ggz|高各庄|GGP|gaogezhuang|ggz|868@ghe|甘河|GAX|ganhe|gh|869@ghe|根河|GEX|genhe|gh|870@gjd|郭家店|GDT|guojiadian|gjd|871@gjz|孤家子|GKT|gujiazi|gjz|872@gla|古浪|GLJ|gulang|gl|873@gla|皋兰|GEJ|gaolan|gl|874@glf|高楼房|GFM|gaoloufang|glf|875@glh|归流河|GHT|guiliuhe|glh|876@gli|关林|GLF|guanlin|gl|877@glu|甘洛|VOW|ganluo|gl|878@glz|郭磊庄|GLP|guoleizhuang|glz|879@gmi|高密|GMK|gaomi|gm|880@gmz|公庙子|GMC|gongmiaozi|gmz|881@gnh|工农湖|GRT|gongnonghu|gnh|882@gns|广宁寺|GNT|guangningsi|gns|883@gnw|广南卫|GNM|guangnanwei|gnw|884@gpi|高平|GPF|gaoping|gp|885@gqb|甘泉北|GEY|ganquanbei|gqb|886@gqc|共青城|GAG|gongqingcheng|gqc|887@gqk|甘旗卡|GQD|ganqika|gqk|888@gqu|甘泉|GQY|ganquan|gq|889@gqz|高桥镇|GZD|gaoqiaozhen|gqz|890@gsh|赶水|GSW|ganshui|gs|891@gsh|灌水|GST|guanshui|gs|892@gsk|孤山口|GSP|gushankou|gsk|893@gso|果松|GSL|guosong|gs|894@gsz|高山子|GSD|gaoshanzi|gsz|895@gsz|嘎什甸子|GXD|gashidianzi|gsdz|896@gta|高台|GTJ|gaotai|gt|897@gta|高滩|GAY|gaotan|gt|898@gti|古田|GTS|gutian|gt|899@gti|官厅|GTP|guanting|gt|900@gtx|官厅西|KEP|guantingxi|gtx|901@gxi|贵溪|GXG|guixi|gx|902@gya|涡阳|GYH|guoyang|gy|903@gyi|巩义|GXF|gongyi|gy|904@gyi|高邑|GIP|gaoyi|gy|905@gyn|巩义南|GYF|gongyinan|gyn|906@gyn|广元南|GAW|guangyuannan|gyn|907@gyu|固原|GUJ|guyuan|gy|908@gyu|菇园|GYL|guyuan|gy|909@gyz|公营子|GYD|gongyingzi|gyz|910@gze|光泽|GZS|guangze|gz|911@gzh|古镇|GNQ|guzhen|gz|912@gzh|瓜州|GZJ|guazhou|gz|913@gzh|高州|GSQ|gaozhou|gz|914@gzh|固镇|GEH|guzhen|gz|915@gzh|盖州|GXT|gaizhou|gz|916@gzj|官字井|GOT|guanzijing|gzj|917@gzp|革镇堡|GZT|gezhenpu|gzb|918@gzs|冠豸山|GSS|guanzhishan|gzs|919@gzx|盖州西|GAT|gaizhouxi|gzx|920@han|红安|HWN|hongan|ha|921@han|淮安南|AMH|huaiannan|han|922@hax|红安西|VXN|honganxi|hax|923@hax|海安县|HIH|haianxian|hax|924@hba|黄柏|HBL|huangbai|hb|925@hbe|海北|HEB|haibei|hb|926@hbi|鹤壁|HAF|hebi|hb|927@hch|华城|VCQ|huacheng|hc|928@hch|合川|WKW|hechuan|hc|929@hch|河唇|HCZ|hechun|hc|930@hch|汉川|HCN|hanchuan|hc|931@hch|海城|HCT|haicheng|hc|932@hct|黑冲滩|HCJ|heichongtan|hct|933@hcu|黄村|HCP|huangcun|hc|934@hcx|海城西|HXT|haichengxi|hcx|935@hde|化德|HGC|huade|hd|936@hdo|洪洞|HDV|hongtong|hd|937@hes|霍尔果斯|HFR|huoerguosi|hegs|938@hfe|横峰|HFG|hengfeng|hf|939@hfw|韩府湾|HXJ|hanfuwan|hfw|940@hgu|汉沽|HGP|hangu|hg|941@hgz|红光镇|IGW|hongguangzhen|hgz|942@hhe|浑河|HHT|hunhe|hh|943@hhg|红花沟|VHD|honghuagou|hhg|944@hht|黄花筒|HUD|huanghuatong|hht|945@hjd|贺家店|HJJ|hejiadian|hjd|946@hji|和静|HJR|hejing|hj|947@hji|红江|HFM|hongjiang|hj|948@hji|黑井|HIM|heijing|hj|949@hji|获嘉|HJF|huojia|hj|950@hji|河津|HJV|hejin|hj|951@hji|涵江|HJS|hanjiang|hj|952@hji|华家|HJT|huajia|hj|953@hjq|杭锦后旗|HDC|hangjinhouqi|hjhq|954@hjx|河间西|HXP|hejianxi|hjx|955@hjz|花家庄|HJM|huajiazhuang|hjz|956@hkn|河口南|HKJ|hekounan|hkn|957@hko|黄口|KOH|huangkou|hk|958@hko|湖口|HKG|hukou|hk|959@hla|呼兰|HUB|hulan|hl|960@hlb|葫芦岛北|HPD|huludaobei|hldb|961@hlh|浩良河|HHB|haolianghe|hlh|962@hlh|哈拉海|HIT|halahai|hlh|963@hli|鹤立|HOB|heli|hl|964@hli|桦林|HIB|hualin|hl|965@hli|黄陵|ULY|huangling|hl|966@hli|海林|HRB|hailin|hl|967@hli|虎林|VLB|hulin|hl|968@hli|寒岭|HAT|hanling|hl|969@hlo|和龙|HLL|helong|hl|970@hlo|海龙|HIL|hailong|hl|971@hls|哈拉苏|HAX|halasu|hls|972@hlt|呼鲁斯太|VTJ|hulusitai|hlst|973@hlz|火连寨|HLT|huolianzhai|hlz|974@hme|黄梅|VEH|huangmei|hm|975@hmy|韩麻营|HYP|hanmaying|hmy|976@hnh|黄泥河|HHL|huangnihe|hnh|977@hni|海宁|HNH|haining|hn|978@hno|惠农|HMJ|huinong|hn|979@hpi|和平|VAQ|heping|hp|980@hpz|花棚子|HZM|huapengzi|hpz|981@hqi|花桥|VQH|huaqiao|hq|982@hqi|宏庆|HEY|hongqing|hq|983@hre|怀仁|HRV|huairen|hr|984@hro|华容|HRN|huarong|hr|985@hsb|华山北|HDY|huashanbei|hsb|986@hsd|黄松甸|HDL|huangsongdian|hsd|987@hsg|和什托洛盖|VSR|heshituoluogai|hstlg|988@hsh|红山|VSB|hongshan|hs|989@hsh|汉寿|VSQ|hanshou|hs|990@hsh|衡山|HSQ|hengshan|hs|991@hsh|黑水|HOT|heishui|hs|992@hsh|惠山|VCH|huishan|hs|993@hsh|虎什哈|HHP|hushiha|hsh|994@hsp|红寺堡|HSJ|hongsipu|hsb|995@hst|虎石台|HUT|hushitai|hst|996@hsw|海石湾|HSO|haishiwan|hsw|997@hsx|衡山西|HEQ|hengshanxi|hsx|998@hsx|红砂岘|VSJ|hongshaxian|hsj|999@hta|黑台|HQB|heitai|ht|1000@hta|桓台|VTK|huantai|ht|1001@hti|和田|VTR|hetian|ht|1002@hto|会同|VTQ|huitong|ht|1003@htz|海坨子|HZT|haituozi|htz|1004@hwa|黑旺|HWK|heiwang|hw|1005@hwa|海湾|RWH|haiwan|hw|1006@hxi|红星|VXB|hongxing|hx|1007@hxi|徽县|HYY|huixian|hx|1008@hxl|红兴隆|VHB|hongxinglong|hxl|1009@hxt|换新天|VTB|huanxintian|hxt|1010@hxt|红岘台|HTJ|hongxiantai|hxt|1011@hya|红彦|VIX|hongyan|hy|1012@hya|合阳|HAY|heyang|hy|1013@hya|海阳|HYK|haiyang|hy|1014@hyd|衡阳东|HVQ|hengyangdong|hyd|1015@hyi|华蓥|HUW|huaying|hy|1016@hyi|汉阴|HQY|hanyin|hy|1017@hyt|黄羊滩|HGJ|huangyangtan|hyt|1018@hyu|汉源|WHW|hanyuan|hy|1019@hyu|湟源|HNO|huangyuan|hy|1020@hyu|河源|VIQ|heyuan|hy|1021@hyu|花园|HUN|huayuan|hy|1022@hyz|黄羊镇|HYJ|huangyangzhen|hyz|1023@hzh|湖州|VZH|huzhou|hz|1024@hzh|化州|HZZ|huazhou|hz|1025@hzh|黄州|VON|huangzhou|hz|1026@hzh|霍州|HZV|huozhou|hz|1027@hzx|惠州西|VXQ|huizhouxi|hzx|1028@jba|巨宝|JRT|jubao|jb|1029@jbi|靖边|JIY|jingbian|jb|1030@jbt|金宝屯|JBD|jinbaotun|jbt|1031@jcb|晋城北|JEF|jinchengbei|jcb|1032@jch|金昌|JCJ|jinchang|jc|1033@jch|鄄城|JCK|juancheng|jc|1034@jch|交城|JNV|jiaocheng|jc|1035@jch|建昌|JFD|jianchang|jc|1036@jde|峻德|JDB|junde|jd|1037@jdi|井店|JFP|jingdian|jd|1038@jdo|鸡东|JOB|jidong|jd|1039@jdu|江都|UDH|jiangdu|jd|1040@jgs|鸡冠山|JST|jiguanshan|jgs|1041@jgt|金沟屯|VGP|jingoutun|jgt|1042@jha|静海|JHP|jinghai|jh|1043@jhe|金河|JHX|jinhe|jh|1044@jhe|锦河|JHB|jinhe|jh|1045@jhe|精河|JHR|jinghe|jh|1046@jhn|精河南|JIR|jinghenan|jhn|1047@jhu|江华|JHZ|jianghua|jh|1048@jhu|建湖|AJH|jianhu|jh|1049@jjg|纪家沟|VJD|jijiagou|jjg|1050@jji|晋江|JJS|jinjiang|jj|1051@jji|江津|JJW|jiangjin|jj|1052@jji|姜家|JJB|jiangjia|jj|1053@jke|金坑|JKT|jinkeng|jk|1054@jli|芨岭|JLJ|jiling|jl|1055@jmc|金马村|JMM|jinmacun|jmc|1056@jme|江门|JWQ|jiangmen|jm|1057@jme|角美|JES|jiaomei|jm|1058@jna|莒南|JOK|junan|jn|1059@jna|井南|JNP|jingnan|jn|1060@jou|建瓯|JVS|jianou|jo|1061@jpe|经棚|JPC|jingpeng|jp|1062@jqi|江桥|JQX|jiangqiao|jq|1063@jsa|九三|SSX|jiusan|js|1064@jsb|金山北|EGH|jinshanbei|jsb|1065@jsh|京山|JCN|jingshan|js|1066@jsh|建始|JRN|jianshi|js|1067@jsh|嘉善|JSH|jiashan|js|1068@jsh|稷山|JVV|jishan|js|1069@jsh|吉舒|JSL|jishu|js|1070@jsh|建设|JET|jianshe|js|1071@jsh|甲山|JOP|jiashan|js|1072@jsj|建三江|JIB|jiansanjiang|jsj|1073@jsn|嘉善南|EAH|jiashannan|jsn|1074@jst|金山屯|JTB|jinshantun|jst|1075@jst|江所田|JOM|jiangsuotian|jst|1076@jta|景泰|JTJ|jingtai|jt|1077@jtn|九台南|JNL|jiutainan|jtn|1078@jwe|吉文|JWX|jiwen|jw|1079@jxi|进贤|JUG|jinxian|jx|1080@jxi|莒县|JKK|juxian|jx|1081@jxi|嘉祥|JUK|jiaxiang|jx|1082@jxi|介休|JXV|jiexiu|jx|1083@jxi|井陉|JJP|jingxing|jx|1084@jxi|嘉兴|JXH|jiaxing|jx|1085@jxn|嘉兴南|EPH|jiaxingnan|jxn|1086@jxz|夹心子|JXT|jiaxinzi|jxz|1087@jya|简阳|JYW|jianyang|jy|1088@jya|揭阳|JRQ|jieyang|jy|1089@jya|建阳|JYS|jianyang|jy|1090@jya|姜堰|UEH|jiangyan|jy|1091@jye|巨野|JYK|juye|jy|1092@jyo|江永|JYZ|jiangyong|jy|1093@jyu|靖远|JYJ|jingyuan|jy|1094@jyu|缙云|JYH|jinyun|jy|1095@jyu|江源|SZL|jiangyuan|jy|1096@jyu|济源|JYF|jiyuan|jy|1097@jyx|靖远西|JXJ|jingyuanxi|jyx|1098@jzb|胶州北|JZK|jiaozhoubei|jzb|1099@jzd|焦作东|WEF|jiaozuodong|jzd|1100@jzh|靖州|JEQ|jingzhou|jz|1101@jzh|荆州|JBN|jingzhou|jz|1102@jzh|金寨|JZH|jinzhai|jz|1103@jzh|晋州|JXP|jinzhou|jz|1104@jzh|胶州|JXK|jiaozhou|jz|1105@jzn|锦州南|JOD|jinzhounan|jzn|1106@jzu|焦作|JOF|jiaozuo|jz|1107@jzw|旧庄窝|JVP|jiuzhuangwo|jzw|1108@jzz|金杖子|JYD|jinzhangzi|jzz|1109@kan|开安|KAT|kaian|ka|1110@kch|库车|KCR|kuche|kc|1111@kch|康城|KCP|kangcheng|kc|1112@kde|库都尔|KDX|kuduer|kde|1113@kdi|宽甸|KDT|kuandian|kd|1114@kdo|克东|KOB|kedong|kd|1115@kji|开江|KAW|kaijiang|kj|1116@kjj|康金井|KJB|kangjinjing|kjj|1117@klq|喀喇其|KQX|kalaqi|klq|1118@klu|开鲁|KLC|kailu|kl|1119@kly|克拉玛依|KHR|kelamayi|klmy|1120@kqi|口前|KQL|kouqian|kq|1121@ksh|奎山|KAB|kuishan|ks|1122@ksh|昆山|KSH|kunshan|ks|1123@ksh|克山|KSB|keshan|ks|1124@kto|开通|KTT|kaitong|kt|1125@kxl|康熙岭|KXZ|kangxiling|kxl|1126@kya|昆阳|KAM|kunyang|ky|1127@kyh|克一河|KHX|keyihe|kyh|1128@kyx|开原西|KXT|kaiyuanxi|kyx|1129@kzh|康庄|KZP|kangzhuang|kz|1130@lbi|来宾|UBZ|laibin|lb|1131@lbi|老边|LLT|laobian|lb|1132@lbx|灵宝西|LPF|lingbaoxi|lbx|1133@lch|龙川|LUQ|longchuan|lc|1134@lch|乐昌|LCQ|lechang|lc|1135@lch|黎城|UCP|licheng|lc|1136@lch|聊城|UCK|liaocheng|lc|1137@lcu|蓝村|LCK|lancun|lc|1138@lda|两当|LDY|liangdang|ld|1139@ldo|林东|LRC|lindong|ld|1140@ldu|乐都|LDO|ledu|ld|1141@ldx|梁底下|LDP|liangdixia|ldx|1142@ldz|六道河子|LVP|liudaohezi|ldhz|1143@lfa|鲁番|LVM|lufan|lf|1144@lfa|廊坊|LJP|langfang|lf|1145@lfa|落垡|LOP|luofa|lf|1146@lfb|廊坊北|LFP|langfangbei|lfb|1147@lfu|老府|UFD|laofu|lf|1148@lga|兰岗|LNB|langang|lg|1149@lgd|龙骨甸|LGM|longgudian|lgd|1150@lgo|芦沟|LOM|lugou|lg|1151@lgo|龙沟|LGJ|longgou|lg|1152@lgu|拉古|LGB|lagu|lg|1153@lha|临海|UFH|linhai|lh|1154@lha|林海|LXX|linhai|lh|1155@lha|拉哈|LHX|laha|lh|1156@lha|凌海|JID|linghai|lh|1157@lhe|柳河|LNL|liuhe|lh|1158@lhe|六合|KLH|liuhe|lh|1159@lhu|龙华|LHP|longhua|lh|1160@lhy|滦河沿|UNP|luanheyan|lhy|1161@lhz|六合镇|LEX|liuhezhen|lhz|1162@ljd|亮甲店|LRT|liangjiadian|ljd|1163@ljd|刘家店|UDT|liujiadian|ljd|1164@ljh|刘家河|LVT|liujiahe|ljh|1165@lji|连江|LKS|lianjiang|lj|1166@lji|李家|LJB|lijia|lj|1167@lji|罗江|LJW|luojiang|lj|1168@lji|廉江|LJZ|lianjiang|lj|1169@lji|庐江|UJH|lujiang|lj|1170@lji|两家|UJT|liangjia|lj|1171@lji|龙江|LJX|longjiang|lj|1172@lji|龙嘉|UJL|longjia|lj|1173@ljk|莲江口|LHB|lianjiangkou|ljk|1174@ljl|蔺家楼|ULK|linjialou|ljl|1175@ljp|李家坪|LIJ|lijiaping|ljp|1176@lka|兰考|LKF|lankao|lk|1177@lko|林口|LKB|linkou|lk|1178@lkp|路口铺|LKQ|lukoupu|lkp|1179@lla|老莱|LAX|laolai|ll|1180@lli|拉林|LAB|lalin|ll|1181@lli|陆良|LRM|luliang|ll|1182@lli|龙里|LLW|longli|ll|1183@lli|零陵|UWZ|lingling|ll|1184@lli|临澧|LWQ|linli|ll|1185@lli|兰棱|LLB|lanling|ll|1186@llo|卢龙|UAP|lulong|ll|1187@lmd|喇嘛甸|LMX|lamadian|lmd|1188@lmd|里木店|LMB|limudian|lmd|1189@lme|洛门|LMJ|luomen|lm|1190@lna|龙南|UNG|longnan|ln|1191@lpi|梁平|UQW|liangping|lp|1192@lpi|罗平|LPM|luoping|lp|1193@lpl|落坡岭|LPP|luopoling|lpl|1194@lps|六盘山|UPJ|liupanshan|lps|1195@lps|乐平市|LPG|lepingshi|lps|1196@lqi|临清|UQK|linqing|lq|1197@lqs|龙泉寺|UQJ|longquansi|lqs|1198@lsb|乐山北|UTW|leshanbei|ls|1199@lsc|乐善村|LUM|leshancun|lsc|1200@lsd|冷水江东|UDQ|lengshuijiangdong|lsjd|1201@lsg|连山关|LGT|lianshanguan|lsg|1202@lsg|流水沟|USP|liushuigou|lsg|1203@lsh|陵水|LIQ|lingshui|ls|1204@lsh|罗山|LRN|luoshan|ls|1205@lsh|鲁山|LAF|lushan|ls|1206@lsh|丽水|USH|lishui|ls|1207@lsh|梁山|LMK|liangshan|ls|1208@lsh|灵石|LSV|lingshi|ls|1209@lsh|露水河|LUL|lushuihe|lsh|1210@lsh|庐山|LSG|lushan|ls|1211@lsp|林盛堡|LBT|linshengpu|lsp|1212@lst|柳树屯|LSD|liushutun|lst|1213@lsz|龙山镇|LAS|longshanzhen|lsz|1214@lsz|梨树镇|LSB|lishuzhen|lsz|1215@lsz|李石寨|LET|lishizhai|lsz|1216@lta|黎塘|LTZ|litang|lt|1217@lta|轮台|LAR|luntai|lt|1218@lta|芦台|LTP|lutai|lt|1219@ltb|龙塘坝|LBM|longtangba|ltb|1220@ltu|濑湍|LVZ|laituan|lt|1221@ltx|骆驼巷|LTJ|luotuoxiang|ltx|1222@lwa|李旺|VLJ|liwang|lw|1223@lwd|莱芜东|LWK|laiwudong|lwd|1224@lws|狼尾山|LRJ|langweishan|lws|1225@lwu|灵武|LNJ|lingwu|lw|1226@lwx|莱芜西|UXK|laiwuxi|lwx|1227@lxi|朗乡|LXB|langxiang|lx|1228@lxi|陇县|LXY|longxian|lx|1229@lxi|临湘|LXQ|linxiang|lx|1230@lxi|芦溪|LUG|luxi|lx|1231@lxi|莱西|LXK|laixi|lx|1232@lxi|林西|LXC|linxi|lx|1233@lxi|滦县|UXP|luanxian|lx|1234@lya|略阳|LYY|lueyang|ly|1235@lya|莱阳|LYK|laiyang|ly|1236@lya|辽阳|LYT|liaoyang|ly|1237@lyb|临沂北|UYK|linyibei|lyb|1238@lyd|凌源东|LDD|lingyuandong|lyd|1239@lyg|连云港|UIH|lianyungang|lyg|1240@lyi|临颍|LNF|linying|ly|1241@lyi|老营|LXL|laoying|ly|1242@lyo|龙游|LMH|longyou|ly|1243@lyu|罗源|LVS|luoyuan|ly|1244@lyu|林源|LYX|linyuan|ly|1245@lyu|涟源|LAQ|lianyuan|ly|1246@lyu|涞源|LYP|laiyuan|ly|1247@lyx|耒阳西|LPQ|leiyangxi|lyx|1248@lze|临泽|LEJ|linze|lz|1249@lzg|龙爪沟|LZT|longzhuagou|lzg|1250@lzh|雷州|UAQ|leizhou|lz|1251@lzh|六枝|LIW|liuzhi|lz|1252@lzh|鹿寨|LIZ|luzhai|lz|1253@lzh|来舟|LZS|laizhou|lz|1254@lzh|龙镇|LZA|longzhen|lz|1255@lzh|拉鲊|LEM|lazha|lz|1256@lzq|兰州新区|LQJ|lanzhouxinqu|lzxq|1257@mas|马鞍山|MAH|maanshan|mas|1258@mba|毛坝|MBY|maoba|mb|1259@mbg|毛坝关|MGY|maobaguan|mbg|1260@mcb|麻城北|MBN|machengbei|mcb|1261@mch|渑池|MCF|mianchi|mc|1262@mch|明城|MCL|mingcheng|mc|1263@mch|庙城|MAP|miaocheng|mc|1264@mcn|渑池南|MNF|mianchinan|mcn|1265@mcp|茅草坪|KPM|maocaoping|mcp|1266@mdh|猛洞河|MUQ|mengdonghe|mdh|1267@mds|磨刀石|MOB|modaoshi|mds|1268@mdu|弥渡|MDF|midu|md|1269@mes|帽儿山|MRB|maoershan|mes|1270@mga|明港|MGN|minggang|mg|1271@mhk|梅河口|MHL|meihekou|mhk|1272@mhu|马皇|MHZ|mahuang|mh|1273@mjg|孟家岗|MGB|mengjiagang|mjg|1274@mla|美兰|MHQ|meilan|ml|1275@mld|汨罗东|MQQ|miluodong|mld|1276@mlh|马莲河|MHB|malianhe|mlh|1277@mli|茅岭|MLZ|maoling|ml|1278@mli|庙岭|MLL|miaoling|ml|1279@mli|茂林|MLD|maolin|ml|1280@mli|穆棱|MLB|muling|ml|1281@mli|马林|MID|malin|ml|1282@mlo|马龙|MGM|malong|ml|1283@mlt|木里图|MUD|mulitu|mlt|1284@mlu|汨罗|MLQ|miluo|ml|1285@mnh|玛纳斯湖|MNR|manasihu|mnsh|1286@mni|冕宁|UGW|mianning|mn|1287@mpa|沐滂|MPQ|mupang|mp|1288@mqh|马桥河|MQB|maqiaohe|mqh|1289@mqi|闽清|MQS|minqing|mq|1290@mqu|民权|MQF|minquan|mq|1291@msh|明水河|MUT|mingshuihe|msh|1292@msh|麻山|MAB|mashan|ms|1293@msh|眉山|MSW|meishan|ms|1294@msw|漫水湾|MKW|manshuiwan|msw|1295@msz|茂舍祖|MOM|maoshezu|msz|1296@msz|米沙子|MST|mishazi|msz|1297@mxi|美溪|MEB|meixi|mx|1298@mxi|勉县|MVY|mianxian|mx|1299@mya|麻阳|MVQ|mayang|my|1300@myb|密云北|MUP|miyunbei|myb|1301@myi|米易|MMW|miyi|my|1302@myu|麦园|MYS|maiyuan|my|1303@myu|墨玉|MUR|moyu|my|1304@mzh|庙庄|MZJ|miaozhuang|mz|1305@mzh|米脂|MEY|mizhi|mz|1306@mzh|明珠|MFQ|mingzhu|mz|1307@nan|宁安|NAB|ningan|na|1308@nan|农安|NAT|nongan|na|1309@nbs|南博山|NBK|nanboshan|nbs|1310@nch|南仇|NCK|nanqiu|nc|1311@ncs|南城司|NSP|nanchengsi|ncs|1312@ncu|宁村|NCZ|ningcun|nc|1313@nde|宁德|NES|ningde|nd|1314@ngc|南观村|NGP|nanguancun|ngc|1315@ngd|南宫东|NFP|nangongdong|ngd|1316@ngl|南关岭|NLT|nanguanling|ngl|1317@ngu|宁国|NNH|ningguo|ng|1318@nha|宁海|NHH|ninghai|nh|1319@nhc|南河川|NHJ|nanhechuan|nhc|1320@nhu|南华|NHS|nanhua|nh|1321@nhz|泥河子|NHD|nihezi|nhz|1322@nji|宁家|NVT|ningjia|nj|1323@nji|南靖|NJS|nanjing|nj|1324@nji|牛家|NJB|niujia|nj|1325@nji|能家|NJD|nengjia|nj|1326@nko|南口|NKP|nankou|nk|1327@nkq|南口前|NKT|nankouqian|nkq|1328@nla|南朗|NNQ|nanlang|nl|1329@nli|乃林|NLD|nailin|nl|1330@nlk|尼勒克|NIR|nileke|nlk|1331@nlu|那罗|ULZ|naluo|nl|1332@nlx|宁陵县|NLF|ninglingxian|nlx|1333@nma|奈曼|NMD|naiman|nm|1334@nmi|宁明|NMZ|ningming|nm|1335@nmu|南木|NMX|nanmu|nm|1336@npn|南平南|NNS|nanpingnan|npn|1337@npu|那铺|NPZ|napu|np|1338@nqi|南桥|NQD|nanqiao|nq|1339@nqu|那曲|NQO|naqu|nq|1340@nqu|暖泉|NQJ|nuanquan|nq|1341@nta|南台|NTT|nantai|nt|1342@nto|南头|NOQ|nantou|nt|1343@nwu|宁武|NWV|ningwu|nw|1344@nwz|南湾子|NWP|nanwanzi|nwz|1345@nxb|南翔北|NEH|nanxiangbei|nxb|1346@nxi|宁乡|NXQ|ningxiang|nx|1347@nxi|内乡|NXF|neixiang|nx|1348@nxt|牛心台|NXT|niuxintai|nxt|1349@nyu|南峪|NUP|nanyu|ny|1350@nzg|娘子关|NIP|niangziguan|nzg|1351@nzh|南召|NAF|nanzhao|nz|1352@nzm|南杂木|NZT|nanzamu|nzm|1353@pan|平安|PAL|pingan|pa|1354@pan|蓬安|PAW|pengan|pa|1355@pay|平安驿|PNO|pinganyi|pay|1356@paz|磐安镇|PAJ|pananzhen|paz|1357@paz|平安镇|PZT|pinganzhen|paz|1358@pcd|蒲城东|PEY|puchengdong|pcd|1359@pch|蒲城|PCY|pucheng|pc|1360@pde|裴德|PDB|peide|pd|1361@pdi|偏店|PRP|piandian|pd|1362@pdx|平顶山西|BFF|pingdingshanxi|pdsx|1363@pdx|坡底下|PXJ|podixia|pdx|1364@pet|瓢儿屯|PRT|piaoertun|pet|1365@pfa|平房|PFB|pingfang|pf|1366@pga|平岗|PGL|pinggang|pg|1367@pgu|平关|PGM|pingguan|pg|1368@pgu|盘关|PAM|panguan|pg|1369@pgu|平果|PGZ|pingguo|pg|1370@phb|徘徊北|PHP|paihuaibei|phb|1371@phk|平河口|PHM|pinghekou|phk|1372@pjb|盘锦北|PBD|panjinbei|pjb|1373@pjd|潘家店|PDP|panjiadian|pjd|1374@pko|皮口|PKT|pikou|pk|1375@pld|普兰店|PLT|pulandian|pld|1376@pli|偏岭|PNT|pianling|pl|1377@psh|平山|PSB|pingshan|ps|1378@psh|彭山|PSW|pengshan|ps|1379@psh|皮山|PSR|pishan|ps|1380@psh|彭水|PHW|pengshui|ps|1381@psh|磐石|PSL|panshi|ps|1382@psh|平社|PSV|pingshe|ps|1383@pta|平台|PVT|pingtai|pt|1384@pti|平田|PTM|pingtian|pt|1385@pti|莆田|PTS|putian|pt|1386@ptq|葡萄菁|PTW|putaojing|ptj|1387@pwa|普湾|PWT|puwan|pw|1388@pwa|平旺|PWV|pingwang|pw|1389@pxg|平型关|PGV|pingxingguan|pxg|1390@pxi|普雄|POW|puxiong|px|1391@pxi|郫县|PWW|pixian|px|1392@pya|平洋|PYX|pingyang|py|1393@pya|彭阳|PYJ|pengyang|py|1394@pya|平遥|PYV|pingyao|py|1395@pyi|平邑|PIK|pingyi|py|1396@pyp|平原堡|PPJ|pingyuanpu|pyp|1397@pyu|平原|PYK|pingyuan|py|1398@pyu|平峪|PYP|pingyu|py|1399@pze|彭泽|PZG|pengze|pz|1400@pzh|邳州|PJH|pizhou|pz|1401@pzh|平庄|PZD|pingzhuang|pz|1402@pzi|泡子|POD|paozi|pz|1403@pzn|平庄南|PND|pingzhuangnan|pzn|1404@qan|乾安|QOT|qianan|qa|1405@qan|庆安|QAB|qingan|qa|1406@qan|迁安|QQP|qianan|qa|1407@qdb|祁东北|QRQ|qidongbei|qd|1408@qdi|七甸|QDM|qidian|qd|1409@qfd|曲阜东|QAK|qufudong|qfd|1410@qfe|庆丰|QFT|qingfeng|qf|1411@qft|奇峰塔|QVP|qifengta|qft|1412@qfu|曲阜|QFK|qufu|qf|1413@qha|琼海|QYQ|qionghai|qh|1414@qhd|秦皇岛|QTP|qinhuangdao|qhd|1415@qhe|千河|QUY|qianhe|qh|1416@qhe|清河|QIP|qinghe|qh|1417@qhm|清河门|QHD|qinghemen|qhm|1418@qhy|清华园|QHP|qinghuayuan|qhy|1419@qji|渠旧|QJZ|qujiu|qj|1420@qji|綦江|QJW|qijiang|qj|1421@qji|潜江|QJN|qianjiang|qj|1422@qji|全椒|INH|quanjiao|qj|1423@qji|秦家|QJB|qinjia|qj|1424@qjp|祁家堡|QBT|qijiapu|qjb|1425@qjx|清涧县|QNY|qingjianxian|qjx|1426@qjz|秦家庄|QZV|qinjiazhuang|qjz|1427@qlh|七里河|QLD|qilihe|qlh|1428@qli|渠黎|QLZ|quli|ql|1429@qli|秦岭|QLY|qinling|ql|1430@qlo|青龙|QIB|qinglong|ql|1431@qls|青龙山|QGH|qinglongshan|qls|1432@qme|祁门|QIH|qimen|qm|1433@qmt|前磨头|QMP|qianmotou|qmt|1434@qsh|青山|QSB|qingshan|qs|1435@qsh|确山|QSN|queshan|qs|1436@qsh|清水|QUJ|qingshui|qs|1437@qsh|前山|QXQ|qianshan|qs|1438@qsy|戚墅堰|QYH|qishuyan|qsy|1439@qti|青田|QVH|qingtian|qt|1440@qto|桥头|QAT|qiaotou|qt|1441@qtx|青铜峡|QTJ|qingtongxia|qtx|1442@qwe|前卫|QWD|qianwei|qw|1443@qwt|前苇塘|QWP|qianweitang|qwt|1444@qxi|渠县|QRW|quxian|qx|1445@qxi|祁县|QXV|qixian|qx|1446@qxi|青县|QXP|qingxian|qx|1447@qxi|桥西|QXJ|qiaoxi|qx|1448@qxu|清徐|QUV|qingxu|qx|1449@qxy|旗下营|QXC|qixiaying|qxy|1450@qya|千阳|QOY|qianyang|qy|1451@qya|沁阳|QYF|qinyang|qy|1452@qya|泉阳|QYL|quanyang|qy|1453@qyb|祁阳北|QVQ|qiyangbei|qy|1454@qyi|七营|QYJ|qiying|qy|1455@qys|庆阳山|QSJ|qingyangshan|qys|1456@qyu|清远|QBQ|qingyuan|qy|1457@qyu|清原|QYT|qingyuan|qy|1458@qzd|钦州东|QDZ|qinzhoudong|qzd|1459@qzh|钦州|QRZ|qinzhou|qz|1460@qzs|青州市|QZK|qingzhoushi|qzs|1461@ran|瑞安|RAH|ruian|ra|1462@rch|荣昌|RCW|rongchang|rc|1463@rch|瑞昌|RCG|ruichang|rc|1464@rga|如皋|RBH|rugao|rg|1465@rgu|容桂|RUQ|ronggui|rg|1466@rqi|任丘|RQP|renqiu|rq|1467@rsh|乳山|ROK|rushan|rs|1468@rsh|融水|RSZ|rongshui|rs|1469@rsh|热水|RSD|reshui|rs|1470@rxi|容县|RXZ|rongxian|rx|1471@rya|饶阳|RVP|raoyang|ry|1472@rya|汝阳|RYF|ruyang|ry|1473@ryh|绕阳河|RHD|raoyanghe|ryh|1474@rzh|汝州|ROF|ruzhou|rz|1475@sba|石坝|OBJ|shiba|sb|1476@sbc|上板城|SBP|shangbancheng|sbc|1477@sbi|施秉|AQW|shibing|sb|1478@sbn|上板城南|OBP|shangbanchengnan|sbcn|1479@sby|世博园|ZWT|shiboyuan|sby|1480@scb|双城北|SBB|shuangchengbei|scb|1481@sch|商城|SWN|shangcheng|sc|1482@sch|莎车|SCR|shache|sc|1483@sch|顺昌|SCS|shunchang|sc|1484@sch|舒城|OCH|shucheng|sc|1485@sch|神池|SMV|shenchi|sc|1486@sch|沙城|SCP|shacheng|sc|1487@sch|石城|SCT|shicheng|sc|1488@scz|山城镇|SCL|shanchengzhen|scz|1489@sda|山丹|SDJ|shandan|sd|1490@sde|顺德|ORQ|shunde|sd|1491@sde|绥德|ODY|suide|sd|1492@sdo|水洞|SIL|shuidong|sd|1493@sdu|商都|SXC|shangdu|sd|1494@sdu|十渡|SEP|shidu|sd|1495@sdw|四道湾|OUD|sidaowan|sdw|1496@sdy|顺德学院|OJQ|shundexueyuan|sdxy|1497@sfa|绅坊|OLH|shenfang|sf|1498@sfe|双丰|OFB|shuangfeng|sf|1499@sft|四方台|STB|sifangtai|sft|1500@sfu|水富|OTW|shuifu|sf|1501@sgk|三关口|OKJ|sanguankou|sgk|1502@sgl|桑根达来|OGC|sanggendalai|sgdl|1503@sgu|韶关|SNQ|shaoguan|sg|1504@sgz|上高镇|SVK|shanggaozhen|sgz|1505@sha|上杭|JBS|shanghang|sh|1506@sha|沙海|SED|shahai|sh|1507@she|松河|SBM|songhe|sh|1508@she|沙河|SHP|shahe|sh|1509@shk|沙河口|SKT|shahekou|shk|1510@shl|赛汗塔拉|SHC|saihantala|shtl|1511@shs|沙河市|VOP|shaheshi|shs|1512@shs|沙后所|SSD|shahousuo|shs|1513@sht|山河屯|SHL|shanhetun|sht|1514@shx|三河县|OXP|sanhexian|shx|1515@shy|四合永|OHD|siheyong|shy|1516@shz|三汇镇|OZW|sanhuizhen|shz|1517@shz|双河镇|SEL|shuanghezhen|shz|1518@shz|石河子|SZR|shihezi|shz|1519@shz|三合庄|SVP|sanhezhuang|shz|1520@sjd|三家店|ODP|sanjiadian|sjd|1521@sjh|水家湖|SQH|shuijiahu|sjh|1522@sjh|沈家河|OJJ|shenjiahe|sjh|1523@sjh|松江河|SJL|songjianghe|sjh|1524@sji|尚家|SJB|shangjia|sj|1525@sji|孙家|SUB|sunjia|sj|1526@sji|沈家|OJB|shenjia|sj|1527@sji|松江|SAH|songjiang|sj|1528@sjk|三江口|SKD|sanjiangkou|sjk|1529@sjl|司家岭|OLK|sijialing|sjl|1530@sjn|松江南|IMH|songjiangnan|sjn|1531@sjn|石景山南|SRP|shijingshannan|sjsn|1532@sjt|邵家堂|SJJ|shaojiatang|sjt|1533@sjx|三江县|SOZ|sanjiangxian|sjx|1534@sjz|三家寨|SMM|sanjiazhai|sjz|1535@sjz|十家子|SJD|shijiazi|sjz|1536@sjz|松江镇|OZL|songjiangzhen|sjz|1537@sjz|施家嘴|SHM|shijiazui|sjz|1538@sjz|深井子|SWT|shenjingzi|sjz|1539@sld|什里店|OMP|shilidian|sld|1540@sle|疏勒|SUR|shule|sl|1541@slh|疏勒河|SHJ|shulehe|slh|1542@slh|舍力虎|VLD|shelihu|slh|1543@sli|石磷|SPB|shilin|sl|1544@sli|双辽|ZJD|shuangliao|sl|1545@sli|绥棱|SIB|suiling|sl|1546@sli|石岭|SOL|shiling|sl|1547@sli|石林|SLM|shilin|sl|1548@sln|石林南|LNM|shilinnan|sln|1549@slo|石龙|SLQ|shilong|sl|1550@slq|萨拉齐|SLC|salaqi|slq|1551@slu|索伦|SNT|suolun|sl|1552@slu|商洛|OLY|shangluo|sl|1553@slz|沙岭子|SLP|shalingzi|slz|1554@smb|石门县北|VFQ|shimenxianbei|smxb|1555@smn|三门峡南|SCF|sanmenxianan|smxn|1556@smx|三门县|OQH|sanmenxian|smx|1557@smx|石门县|OMQ|shimenxian|smx|1558@smx|三门峡西|SXF|sanmenxiaxi|smxx|1559@sni|肃宁|SYP|suning|sn|1560@son|宋|SOB|song|s|1561@spa|双牌|SBZ|shuangpai|sp|1562@spd|四平东|PPT|sipingdong|spd|1563@spi|遂平|SON|suiping|sp|1564@spt|沙坡头|SFJ|shapotou|spt|1565@sqn|商丘南|SPF|shangqiunan|sqn|1566@squ|水泉|SID|shuiquan|sq|1567@sqx|石泉县|SXY|shiquanxian|sqx|1568@sqz|石桥子|SQT|shiqiaozi|sqz|1569@src|石人城|SRB|shirencheng|src|1570@sre|石人|SRL|shiren|sr|1571@ssh|山市|SQB|shanshi|ss|1572@ssh|神树|SWB|shenshu|ss|1573@ssh|鄯善|SSR|shanshan|ss|1574@ssh|三水|SJQ|sanshui|ss|1575@ssh|泗水|OSK|sishui|ss|1576@ssh|石山|SAD|shishan|ss|1577@ssh|松树|SFT|songshu|ss|1578@ssh|首山|SAT|shoushan|ss|1579@ssj|三十家|SRD|sanshijia|ssj|1580@ssp|三十里堡|SST|sanshilipu|sslb|1581@ssz|松树镇|SSL|songshuzhen|ssz|1582@sta|松桃|MZQ|songtao|st|1583@sth|索图罕|SHX|suotuhan|sth|1584@stj|三堂集|SDH|santangji|stj|1585@sto|石头|OTB|shitou|st|1586@sto|神头|SEV|shentou|st|1587@stu|沙沱|SFM|shatuo|st|1588@swa|上万|SWP|shangwan|sw|1589@swu|孙吴|SKB|sunwu|sw|1590@swx|沙湾县|SXR|shawanxian|swx|1591@sxi|遂溪|SXZ|suixi|sx|1592@sxi|沙县|SAS|shaxian|sx|1593@sxi|歙县|OVH|shexian|sx|1594@sxi|绍兴|SOH|shaoxing|sx|1595@sxi|石岘|SXL|shixian|sj|1596@sxp|上西铺|SXM|shangxipu|sxp|1597@sxz|石峡子|SXJ|shixiazi|sxz|1598@sya|绥阳|SYB|suiyang|sy|1599@sya|沭阳|FMH|shuyang|sy|1600@sya|寿阳|SYV|shouyang|sy|1601@sya|水洋|OYP|shuiyang|sy|1602@syc|三阳川|SYJ|sanyangchuan|syc|1603@syd|上腰墩|SPJ|shangyaodun|syd|1604@syi|三营|OEJ|sanying|sy|1605@syi|顺义|SOP|shunyi|sy|1606@syj|三义井|OYD|sanyijing|syj|1607@syp|三源浦|SYL|sanyuanpu|syp|1608@syu|三原|SAY|sanyuan|sy|1609@syu|上虞|BDH|shangyu|sy|1610@syu|上园|SUD|shangyuan|sy|1611@syu|水源|OYJ|shuiyuan|sy|1612@syz|桑园子|SAJ|sangyuanzi|syz|1613@szb|绥中北|SND|suizhongbei|szb|1614@szb|苏州北|OHH|suzhoubei|szb|1615@szd|宿州东|SRH|suzhoudong|szd|1616@szd|深圳东|BJQ|shenzhendong|szd|1617@szh|深州|OZP|shenzhou|sz|1618@szh|孙镇|OZY|sunzhen|sz|1619@szh|绥中|SZD|suizhong|sz|1620@szh|尚志|SZB|shangzhi|sz|1621@szh|师庄|SNM|shizhuang|sz|1622@szi|松滋|SIN|songzi|sz|1623@szo|师宗|SEM|shizong|sz|1624@szq|苏州园区|KAH|suzhouyuanqu|szyq|1625@szq|苏州新区|ITH|suzhouxinqu|szxq|1626@tan|泰安|TMK|taian|ta|1627@tan|台安|TID|taian|ta|1628@tay|通安驿|TAJ|tonganyi|tay|1629@tba|桐柏|TBF|tongbai|tb|1630@tbe|通北|TBB|tongbei|tb|1631@tch|汤池|TCX|tangchi|tc|1632@tch|桐城|TTH|tongcheng|tc|1633@tch|郯城|TZK|tancheng|tc|1634@tch|铁厂|TCL|tiechang|tc|1635@tcu|桃村|TCK|taocun|tc|1636@tda|通道|TRQ|tongdao|td|1637@tdo|田东|TDZ|tiandong|td|1638@tga|天岗|TGL|tiangang|tg|1639@tgl|土贵乌拉|TGC|tuguiwula|tgwl|1640@tgo|通沟|TOL|tonggou|tg|1641@tgu|太谷|TGV|taigu|tg|1642@tha|塔哈|THX|taha|th|1643@tha|棠海|THM|tanghai|th|1644@the|唐河|THF|tanghe|th|1645@the|泰和|THG|taihe|th|1646@thu|太湖|TKH|taihu|th|1647@tji|团结|TIX|tuanjie|tj|1648@tjj|谭家井|TNJ|tanjiajing|tjj|1649@tjt|陶家屯|TOT|taojiatun|tjt|1650@tjw|唐家湾|PDQ|tangjiawan|tjw|1651@tjz|统军庄|TZP|tongjunzhuang|tjz|1652@tka|泰康|TKX|taikang|tk|1653@tld|吐列毛杜|TMD|tuliemaodu|tlmd|1654@tlh|图里河|TEX|tulihe|tlh|1655@tli|亭亮|TIZ|tingliang|tl|1656@tli|田林|TFZ|tianlin|tl|1657@tli|铜陵|TJH|tongling|tl|1658@tli|铁力|TLB|tieli|tl|1659@tlx|铁岭西|PXT|tielingxi|tlx|1660@tmb|图们北|QSL|tumenbei|tmb|1661@tme|天门|TMN|tianmen|tm|1662@tmn|天门南|TNN|tianmennan|tmn|1663@tms|太姥山|TLS|taimushan|tms|1664@tmt|土牧尔台|TRC|tumuertai|tmet|1665@tmz|土门子|TCJ|tumenzi|tmz|1666@tna|潼南|TVW|tongnan|tn|1667@tna|洮南|TVT|taonan|tn|1668@tpc|太平川|TIT|taipingchuan|tpc|1669@tpz|太平镇|TEB|taipingzhen|tpz|1670@tqi|图强|TQX|tuqiang|tq|1671@tqi|台前|TTK|taiqian|tq|1672@tql|天桥岭|TQL|tianqiaoling|tql|1673@tqz|土桥子|TQJ|tuqiaozi|tqz|1674@tsc|汤山城|TCT|tangshancheng|tsc|1675@tsh|桃山|TAB|taoshan|ts|1676@tsz|塔石嘴|TIM|tashizui|tsz|1677@ttu|通途|TUT|tongtu|tt|1678@twh|汤旺河|THB|tangwanghe|twh|1679@txi|同心|TXJ|tongxin|tx|1680@txi|土溪|TSW|tuxi|tx|1681@txi|桐乡|TCH|tongxiang|tx|1682@tya|田阳|TRZ|tianyang|ty|1683@tyi|天义|TND|tianyi|ty|1684@tyi|汤阴|TYF|tangyin|ty|1685@tyl|驼腰岭|TIL|tuoyaoling|tyl|1686@tys|太阳山|TYJ|taiyangshan|tys|1687@tyu|汤原|TYB|tangyuan|ty|1688@tyy|塔崖驿|TYP|tayayi|tyy|1689@tzd|滕州东|TEK|tengzhoudong|tzd|1690@tzh|台州|TZH|taizhou|tz|1691@tzh|天祝|TZJ|tianzhu|tz|1692@tzh|滕州|TXK|tengzhou|tz|1693@tzh|天镇|TZV|tianzhen|tz|1694@tzl|桐子林|TEW|tongzilin|tzl|1695@tzs|天柱山|QWH|tianzhushan|tzs|1696@wan|文安|WBP|wenan|wa|1697@wan|武安|WAP|wuan|wa|1698@waz|王安镇|WVP|wanganzhen|waz|1699@wca|旺苍|WEW|wangcang|wc|1700@wcg|五叉沟|WCT|wuchagou|wcg|1701@wch|文昌|WEQ|wenchang|wc|1702@wch|温春|WDB|wenchun|wc|1703@wdc|五大连池|WRB|wudalianchi|wdlc|1704@wde|文登|WBK|wendeng|wd|1705@wdg|五道沟|WDL|wudaogou|wdg|1706@wdh|五道河|WHP|wudaohe|wdh|1707@wdi|文地|WNZ|wendi|wd|1708@wdo|卫东|WVT|weidong|wd|1709@wds|武当山|WRN|wudangshan|wds|1710@wdu|望都|WDP|wangdu|wd|1711@weh|乌尔旗汗|WHX|wuerqihan|weqh|1712@wfa|潍坊|WFK|weifang|wf|1713@wft|万发屯|WFB|wanfatun|wft|1714@wfu|王府|WUT|wangfu|wf|1715@wfx|瓦房店西|WXT|wafangdianxi|wfdx|1716@wga|王岗|WGB|wanggang|wg|1717@wgo|武功|WGY|wugong|wg|1718@wgo|湾沟|WGL|wangou|wg|1719@wgt|吴官田|WGM|wuguantian|wgt|1720@wha|乌海|WVC|wuhai|wh|1721@whe|苇河|WHB|weihe|wh|1722@whu|卫辉|WHF|weihui|wh|1723@wjc|吴家川|WCJ|wujiachuan|wjc|1724@wji|五家|WUB|wujia|wj|1725@wji|威箐|WAM|weiqing|wq|1726@wji|午汲|WJP|wuji|wj|1727@wji|渭津|WJL|weijin|wj|1728@wjw|王家湾|WJJ|wangjiawan|wjw|1729@wke|倭肯|WQB|woken|wk|1730@wks|五棵树|WKT|wukeshu|wks|1731@wlb|五龙背|WBT|wulongbei|wlb|1732@wld|乌兰哈达|WLC|wulanhada|wlhd|1733@wle|万乐|WEB|wanle|wl|1734@wlg|瓦拉干|WVX|walagan|wlg|1735@wli|温岭|VHH|wenling|wl|1736@wli|五莲|WLK|wulian|wl|1737@wlq|乌拉特前旗|WQC|wulateqianqi|wltqq|1738@wls|乌拉山|WSC|wulashan|wls|1739@wlt|卧里屯|WLX|wolitun|wlt|1740@wnb|渭南北|WBY|weinanbei|wnb|1741@wne|乌奴耳|WRX|wunuer|wne|1742@wni|万宁|WNQ|wanning|wn|1743@wni|万年|WWG|wannian|wn|1744@wnn|渭南南|WVY|weinannan|wnn|1745@wnz|渭南镇|WNJ|weinanzhen|wnz|1746@wpi|沃皮|WPT|wopi|wp|1747@wpu|吴堡|WUY|wupu|wb|1748@wqi|吴桥|WUP|wuqiao|wq|1749@wqi|汪清|WQL|wangqing|wq|1750@wqi|武清|WWP|wuqing|wq|1751@wsh|武山|WSJ|wushan|ws|1752@wsh|文水|WEV|wenshui|ws|1753@wsz|魏善庄|WSP|weishanzhuang|wsz|1754@wto|王瞳|WTP|wangtong|wt|1755@wts|五台山|WSV|wutaishan|wts|1756@wtz|王团庄|WZJ|wangtuanzhuang|wtz|1757@wwu|五五|WVR|wuwu|ww|1758@wxd|无锡东|WGH|wuxidong|wxd|1759@wxi|卫星|WVB|weixing|wx|1760@wxi|闻喜|WXV|wenxi|wx|1761@wxi|武乡|WVV|wuxiang|wx|1762@wxq|无锡新区|IFH|wuxixinqu|wxxq|1763@wxu|武穴|WXN|wuxue|wx|1764@wxu|吴圩|WYZ|wuxu|wy|1765@wya|王杨|WYB|wangyang|wy|1766@wyi|五营|WWB|wuying|wy|1767@wyi|武义|RYH|wuyi|wy|1768@wyt|瓦窑田|WIM|wayaotian|wjt|1769@wyu|五原|WYC|wuyuan|wy|1770@wzg|苇子沟|WZL|weizigou|wzg|1771@wzh|韦庄|WZY|weizhuang|wz|1772@wzh|五寨|WZV|wuzhai|wz|1773@wzt|王兆屯|WZB|wangzhaotun|wzt|1774@wzz|微子镇|WQP|weizizhen|wzz|1775@wzz|魏杖子|WKD|weizhangzi|wzz|1776@xan|新安|EAM|xinan|xa|1777@xan|兴安|XAZ|xingan|xa|1778@xax|新安县|XAF|xinanxian|xax|1779@xba|新保安|XAP|xinbaoan|xba|1780@xbc|下板城|EBP|xiabancheng|xbc|1781@xbl|西八里|XLP|xibali|xbl|1782@xch|宣城|ECH|xuancheng|xc|1783@xch|兴城|XCD|xingcheng|xc|1784@xcu|小村|XEM|xiaocun|xc|1785@xcy|新绰源|XRX|xinchuoyuan|xcy|1786@xcz|下城子|XCB|xiachengzi|xcz|1787@xcz|新城子|XCT|xinchengzi|xcz|1788@xde|喜德|EDW|xide|xd|1789@xdj|小得江|EJM|xiaodejiang|xdj|1790@xdm|西大庙|XMP|xidamiao|xdm|1791@xdo|小董|XEZ|xiaodong|xd|1792@xdo|小东|XOD|xiaodong|xdo|1793@xfe|息烽|XFW|xifeng|xf|1794@xfe|信丰|EFG|xinfeng|xf|1795@xfe|襄汾|XFV|xiangfen|xf|1796@xga|新干|EGG|xingan|xg|1797@xga|孝感|XGN|xiaogan|xg|1798@xgc|西固城|XUJ|xigucheng|xgc|1799@xgu|西固|XIJ|xigu|xg|1800@xgy|夏官营|XGJ|xiaguanying|xgy|1801@xgz|西岗子|NBB|xigangzi|xgz|1802@xhe|襄河|XXB|xianghe|xh|1803@xhe|新和|XIR|xinhe|xh|1804@xhe|宣和|XWJ|xuanhe|xh|1805@xhj|斜河涧|EEP|xiehejian|xhj|1806@xht|新华屯|XAX|xinhuatun|xht|1807@xhu|新华|XHB|xinhua|xh|1808@xhu|新化|EHQ|xinhua|xh|1809@xhu|宣化|XHP|xuanhua|xh|1810@xhx|兴和西|XEC|xinghexi|xhx|1811@xhy|小河沿|XYD|xiaoheyan|xhy|1812@xhy|下花园|XYP|xiahuayuan|xhy|1813@xhz|小河镇|EKY|xiaohezhen|xhz|1814@xji|徐家|XJB|xujia|xj|1815@xji|峡江|EJG|xiajiang|xj|1816@xji|新绛|XJV|xinjiang|xj|1817@xji|辛集|ENP|xinji|xj|1818@xji|新江|XJM|xinjiang|xj|1819@xjk|西街口|EKM|xijiekou|xjk|1820@xjt|许家屯|XJT|xujiatun|xjt|1821@xjt|许家台|XTJ|xujiatai|xjt|1822@xjz|谢家镇|XMT|xiejiazhen|xjz|1823@xka|兴凯|EKB|xingkai|xk|1824@xla|小榄|EAQ|xiaolan|xl|1825@xla|香兰|XNB|xianglan|xl|1826@xld|兴隆店|XDD|xinglongdian|xld|1827@xle|新乐|ELP|xinle|xl|1828@xli|新林|XPX|xinlin|xl|1829@xli|小岭|XLB|xiaoling|xl|1830@xli|新李|XLJ|xinli|xl|1831@xli|西林|XYB|xilin|xl|1832@xli|西柳|GCT|xiliu|xl|1833@xli|仙林|XPH|xianlin|xl|1834@xlt|新立屯|XLD|xinlitun|xlt|1835@xlz|兴隆镇|XZB|xinglongzhen|xlz|1836@xlz|新立镇|XGT|xinlizhen|xlz|1837@xmi|新民|XMD|xinmin|xm|1838@xms|西麻山|XMB|ximashan|xms|1839@xmt|下马塘|XAT|xiamatang|xmt|1840@xna|孝南|XNV|xiaonan|xn|1841@xnb|咸宁北|XRN|xianningbei|xnb|1842@xni|兴宁|ENQ|xingning|xn|1843@xni|咸宁|XNN|xianning|xn|1844@xpd|犀浦东|XAW|xipudong|xpd|1845@xpi|西平|XPN|xiping|xp|1846@xpi|兴平|XPY|xingping|xp|1847@xpt|新坪田|XPM|xinpingtian|xpt|1848@xpu|霞浦|XOS|xiapu|xp|1849@xpu|溆浦|EPQ|xupu|xp|1850@xpu|犀浦|XIW|xipu|xp|1851@xqi|新青|XQB|xinqing|xq|1852@xqi|新邱|XQD|xinqiu|xq|1853@xqp|兴泉堡|XQJ|xingquanbu|xqp|1854@xrq|仙人桥|XRL|xianrenqiao|xrq|1855@xsg|小寺沟|ESP|xiaosigou|xsg|1856@xsh|杏树|XSB|xingshu|xs|1857@xsh|夏石|XIZ|xiashi|xs|1858@xsh|浠水|XZN|xishui|xs|1859@xsh|下社|XSV|xiashe|xs|1860@xsh|徐水|XSP|xushui|xs|1861@xsh|小哨|XAM|xiaoshao|xs|1862@xsp|新松浦|XOB|xinsongpu|xsp|1863@xst|杏树屯|XDT|xingshutun|xst|1864@xsw|许三湾|XSJ|xusanwan|xsw|1865@xta|湘潭|XTQ|xiangtan|xt|1866@xta|邢台|XTP|xingtai|xt|1867@xtx|仙桃西|XAN|xiantaoxi|xtx|1868@xtz|下台子|EIP|xiataizi|xtz|1869@xwe|徐闻|XJQ|xuwen|xw|1870@xwp|新窝铺|EPD|xinwopu|xwp|1871@xwu|修武|XWF|xiuwu|xw|1872@xxi|新县|XSN|xinxian|xx|1873@xxi|息县|ENN|xixian|xx|1874@xxi|西乡|XQY|xixiang|xx|1875@xxi|湘乡|XXQ|xiangxiang|xx|1876@xxi|西峡|XIF|xixia|xx|1877@xxi|孝西|XOV|xiaoxi|xx|1878@xxj|小新街|XXM|xiaoxinjie|xxj|1879@xxx|新兴县|XGQ|xinxingxian|xxx|1880@xxz|西小召|XZC|xixiaozhao|xxz|1881@xxz|小西庄|XXP|xiaoxizhuang|xxz|1882@xya|向阳|XDB|xiangyang|xy|1883@xya|旬阳|XUY|xunyang|xy|1884@xyb|旬阳北|XBY|xunyangbei|xyb|1885@xyd|襄阳东|XWN|xiangyangdong|xyd|1886@xye|兴业|SNZ|xingye|xy|1887@xyg|小雨谷|XHM|xiaoyugu|xyg|1888@xyi|信宜|EEQ|xinyi|xy|1889@xyj|小月旧|XFM|xiaoyuejiu|xyj|1890@xyq|小扬气|XYX|xiaoyangqi|xyq|1891@xyu|祥云|EXM|xiangyun|xy|1892@xyu|襄垣|EIF|xiangyuan|xy|1893@xyx|夏邑县|EJH|xiayixian|xyx|1894@xyy|新友谊|EYB|xinyouyi|xyy|1895@xyz|新阳镇|XZJ|xinyangzhen|xyz|1896@xzd|徐州东|UUH|xuzhoudong|xzd|1897@xzf|新帐房|XZX|xinzhangfang|xzf|1898@xzh|悬钟|XRP|xuanzhong|xz|1899@xzh|新肇|XZT|xinzhao|xz|1900@xzh|忻州|XXV|xinzhou|xz|1901@xzi|汐子|XZD|xizi|xz|1902@xzm|西哲里木|XRD|xizhelimu|xzlm|1903@xzz|新杖子|ERP|xinzhangzi|xzz|1904@yan|姚安|YAC|yaoan|ya|1905@yan|依安|YAX|yian|ya|1906@yan|永安|YAS|yongan|ya|1907@yax|永安乡|YNB|yonganxiang|yax|1908@ybl|亚布力|YBB|yabuli|ybl|1909@ybs|元宝山|YUD|yuanbaoshan|ybs|1910@yca|羊草|YAB|yangcao|yc|1911@ycd|秧草地|YKM|yangcaodi|ycd|1912@ych|阳澄湖|AIH|yangchenghu|ych|1913@ych|迎春|YYB|yingchun|yc|1914@ych|叶城|YER|yecheng|yc|1915@ych|盐池|YKJ|yanchi|yc|1916@ych|砚川|YYY|yanchuan|yc|1917@ych|阳春|YQQ|yangchun|yc|1918@ych|宜城|YIN|yicheng|yc|1919@ych|应城|YHN|yingcheng|yc|1920@ych|禹城|YCK|yucheng|yc|1921@ych|晏城|YEK|yancheng|yc|1922@ych|羊场|YED|yangchang|yc|1923@ych|阳城|YNF|yangcheng|yc|1924@ych|阳岔|YAL|yangcha|yc|1925@ych|郓城|YPK|yuncheng|yc|1926@ych|雁翅|YAP|yanchi|yc|1927@ycl|云彩岭|ACP|yuncailing|ycl|1928@ycx|虞城县|IXH|yuchengxian|ycx|1929@ycz|营城子|YCT|yingchengzi|ycz|1930@yde|永登|YDJ|yongdeng|yd|1931@yde|英德|YDQ|yingde|yd|1932@ydi|尹地|YDM|yindi|yd|1933@ydi|永定|YGS|yongding|yd|1934@yds|雁荡山|YGH|yandangshan|yds|1935@ydu|于都|YDG|yudu|yd|1936@ydu|园墩|YAJ|yuandun|yd|1937@ydx|英德西|IIQ|yingdexi|ydx|1938@yfy|永丰营|YYM|yongfengying|yfy|1939@yga|杨岗|YRB|yanggang|yg|1940@yga|阳高|YOV|yanggao|yg|1941@ygu|阳谷|YIK|yanggu|yg|1942@yha|友好|YOB|youhao|yh|1943@yha|余杭|EVH|yuhang|yh|1944@yhc|沿河城|YHP|yanhecheng|yhc|1945@yhu|岩会|AEP|yanhui|yh|1946@yjh|羊臼河|YHM|yangjiuhe|yjh|1947@yji|永嘉|URH|yongjia|yj|1948@yji|营街|YAM|yingjie|yj|1949@yji|盐津|AEW|yanjin|yj|1950@yji|余江|YHG|yujiang|yj|1951@yji|燕郊|AJP|yanjiao|yj|1952@yji|姚家|YAT|yaojia|yj|1953@yjj|岳家井|YGJ|yuejiajing|yjj|1954@yjp|一间堡|YJT|yijianpu|yjb|1955@yjs|英吉沙|YIR|yingjisha|yjs|1956@yjs|云居寺|AFP|yunjusi|yjs|1957@yjz|燕家庄|AZK|yanjiazhuang|yjz|1958@yka|永康|RFH|yongkang|yk|1959@ykd|营口东|YGT|yingkoudong|ykd|1960@yla|银浪|YJX|yinlang|yl|1961@yla|永郎|YLW|yonglang|yl|1962@ylb|宜良北|YSM|yiliangbei|ylb|1963@yld|永乐店|YDY|yongledian|yld|1964@ylh|伊拉哈|YLX|yilaha|ylh|1965@yli|伊林|YLB|yilin|yl|1966@yli|杨陵|YSY|yangling|yl|1967@yli|彝良|ALW|yiliang|yl|1968@yli|杨林|YLM|yanglin|yl|1969@ylp|余粮堡|YLD|yuliangpu|ylb|1970@ylq|杨柳青|YQP|yangliuqing|ylq|1971@ylt|月亮田|YUM|yueliangtian|ylt|1972@ylw|亚龙湾|TWQ|yalongwan|ylw|1973@yma|义马|YMF|yima|ym|1974@yme|玉门|YXJ|yumen|ym|1975@yme|云梦|YMN|yunmeng|ym|1976@ymo|元谋|YMM|yuanmou|ym|1977@ymp|阳明堡|YVV|yangmingbu|ymp|1978@yms|一面山|YST|yimianshan|yms|1979@yna|沂南|YNK|yinan|yn|1980@yna|宜耐|YVM|yinai|yn|1981@ynd|伊宁东|YNR|yiningdong|ynd|1982@yps|营盘水|YZJ|yingpanshui|yps|1983@ypu|羊堡|ABM|yangpu|yp|1984@yqb|阳泉北|YPP|yangquanbei|yqb|1985@yqi|乐清|UPH|yueqing|yq|1986@yqi|焉耆|YSR|yanqi|yq|1987@yqi|源迁|AQK|yuanqian|yq|1988@yqt|姚千户屯|YQT|yaoqianhutun|yqht|1989@yqu|阳曲|YQV|yangqu|yq|1990@ysg|榆树沟|YGP|yushugou|ysg|1991@ysh|月山|YBF|yueshan|ys|1992@ysh|玉石|YSJ|yushi|ys|1993@ysh|偃师|YSF|yanshi|ys|1994@ysh|沂水|YUK|yishui|ys|1995@ysh|榆社|YSV|yushe|ys|1996@ysh|窑上|ASP|yaoshang|ys|1997@ysh|元氏|YSP|yuanshi|ys|1998@ysl|杨树岭|YAD|yangshuling|ysl|1999@ysp|野三坡|AIP|yesanpo|ysp|2000@yst|榆树屯|YSX|yushutun|yst|2001@yst|榆树台|YUT|yushutai|yst|2002@ysz|鹰手营子|YIP|yingshouyingzi|ysyz|2003@yta|源潭|YTQ|yuantan|yt|2004@ytp|牙屯堡|YTZ|yatunpu|ytb|2005@yts|烟筒山|YSL|yantongshan|yts|2006@ytt|烟筒屯|YUX|yantongtun|ytt|2007@yws|羊尾哨|YWM|yangweishao|yws|2008@yxi|越西|YHW|yuexi|yx|2009@yxi|攸县|YOG|youxian|yx|2010@yxi|玉溪|YXM|yuxi|yx|2011@yxi|永修|ACG|yongxiu|yx|2012@yya|弋阳|YIG|yiyang|yy|2013@yya|酉阳|AFW|youyang|yy|2014@yya|余姚|YYH|yuyao|yy|2015@yyd|岳阳东|YIQ|yueyangdong|yyd|2016@yyi|阳邑|ARP|yangyi|yy|2017@yyu|鸭园|YYL|yayuan|yy|2018@yyz|鸳鸯镇|YYJ|yuanyangzhen|yyz|2019@yzb|燕子砭|YZY|yanzibian|yzb|2020@yzh|宜州|YSZ|yizhou|yz|2021@yzh|仪征|UZH|yizheng|yz|2022@yzh|兖州|YZK|yanzhou|yz|2023@yzi|迤资|YQM|yizi|yz|2024@yzw|羊者窝|AEM|yangzhewo|wzw|2025@yzz|杨杖子|YZD|yangzhangzi|yzz|2026@zan|镇安|ZEY|zhenan|za|2027@zan|治安|ZAD|zhian|za|2028@zba|招柏|ZBP|zhaobai|zb|2029@zbw|张百湾|ZUP|zhangbaiwan|zbw|2030@zcc|中川机场|ZJJ|zhongchuanjichang|zcjc|2031@zch|枝城|ZCN|zhicheng|zc|2032@zch|子长|ZHY|zichang|zc|2033@zch|诸城|ZQK|zhucheng|zc|2034@zch|邹城|ZIK|zoucheng|zc|2035@zch|赵城|ZCV|zhaocheng|zc|2036@zda|章党|ZHT|zhangdang|zd|2037@zdi|正定|ZDP|zhengding|zd|2038@zdo|肇东|ZDB|zhaodong|zd|2039@zfp|照福铺|ZFM|zhaofupu|zfp|2040@zgt|章古台|ZGD|zhanggutai|zgt|2041@zgu|赵光|ZGB|zhaoguang|zg|2042@zhe|中和|ZHX|zhonghe|zh|2043@zhm|中华门|VNH|zhonghuamen|zhm|2044@zjb|枝江北|ZIN|zhijiangbei|zjb|2045@zjc|钟家村|ZJY|zhongjiacun|zjc|2046@zjg|朱家沟|ZUB|zhujiagou|zjg|2047@zjg|紫荆关|ZYP|zijingguan|zjg|2048@zji|周家|ZOB|zhoujia|zj|2049@zji|诸暨|ZDH|zhuji|zj|2050@zjn|镇江南|ZEH|zhenjiangnan|zjn|2051@zjt|周家屯|ZOD|zhoujiatun|zjt|2052@zjw|褚家湾|CWJ|zhujiawan|cjw|2053@zjx|湛江西|ZWQ|zhanjiangxi|zjx|2054@zjy|朱家窑|ZUJ|zhujiayao|zjy|2055@zjz|曾家坪子|ZBW|zengjiapingzi|zjpz|2056@zla|张兰|ZLV|zhanglan|zla|2057@zla|镇赉|ZLT|zhenlai|zl|2058@zli|枣林|ZIV|zaolin|zl|2059@zlt|扎鲁特|ZLD|zhalute|zlt|2060@zlx|扎赉诺尔西|ZXX|zhalainuoerxi|zlnex|2061@zmt|樟木头|ZOQ|zhangmutou|zmt|2062@zmu|中牟|ZGF|zhongmu|zm|2063@znd|中宁东|ZDJ|zhongningdong|znd|2064@zni|中宁|VNJ|zhongning|zn|2065@znn|中宁南|ZNJ|zhongningnan|znn|2066@zpi|镇平|ZPF|zhenping|zp|2067@zpi|漳平|ZPS|zhangping|zp|2068@zpu|泽普|ZPR|zepu|zp|2069@zqi|枣强|ZVP|zaoqiang|zq|2070@zqi|张桥|ZQY|zhangqiao|zq|2071@zqi|章丘|ZTK|zhangqiu|zq|2072@zrh|朱日和|ZRC|zhurihe|zrh|2073@zrl|泽润里|ZLM|zerunli|zrl|2074@zsb|中山北|ZGQ|zhongshanbei|zsb|2075@zsd|樟树东|ZOG|zhangshudong|zsd|2076@zsh|中山|ZSQ|zhongshan|zs|2077@zsh|柞水|ZSY|zhashui|zs|2078@zsh|钟山|ZSZ|zhongshan|zs|2079@zsh|樟树|ZSG|zhangshu|zs|2080@zwo|珠窝|ZOP|zhuwo|zw|2081@zwt|张维屯|ZWB|zhangweitun|zwt|2082@zwu|彰武|ZWD|zhangwu|zw|2083@zxi|棕溪|ZOY|zongxi|zx|2084@zxi|钟祥|ZTN|zhongxiang|zx|2085@zxi|资溪|ZXS|zixi|zx|2086@zxi|镇西|ZVT|zhenxi|zx|2087@zxi|张辛|ZIP|zhangxin|zx|2088@zxq|正镶白旗|ZXC|zhengxiangbaiqi|zxbq|2089@zya|紫阳|ZVY|ziyang|zy|2090@zya|枣阳|ZYN|zaoyang|zy|2091@zyb|竹园坝|ZAW|zhuyuanba|zyb|2092@zye|张掖|ZYJ|zhangye|zy|2093@zyu|镇远|ZUW|zhenyuan|zy|2094@zyx|朱杨溪|ZXW|zhuyangxi|zyx|2095@zzd|漳州东|GOS|zhangzhoudong|zzd|2096@zzh|漳州|ZUS|zhangzhou|zz|2097@zzh|壮志|ZUX|zhuangzhi|zz|2098@zzh|子洲|ZZY|zizhou|zz|2099@zzh|中寨|ZZM|zhongzhai|zz|2100@zzh|涿州|ZXP|zhuozhou|zz|2101@zzi|咋子|ZAL|zhazi|zz|2102@zzs|卓资山|ZZC|zhuozishan|zzs|2103@zzx|株洲西|ZAQ|zhuzhouxi|zzx|2104@are|安仁|ARG|anren|ar|2105@atx|安图西|AXL|antuxi|atx|2106@ayd|安阳东|ADF|anyangdong|ayd|2107@bch|栟茶|FWH|bencha|bc|2108@bdd|保定东|BMP|baodingdong|bdd|2109@bha|滨海|FHP|binhai|bh|2110@bhb|滨海北|FCP|binhaibei|bhb|2111@bjn|宝鸡南|BBY|baojinan|bjn|2112@bqi|宝清|BUB|baoqing|bq|2113@bxc|本溪新城|BVT|benxixincheng|bxxc|2114@bxi|彬县|BXY|binxian|bx|2115@bya|宾阳|UKZ|binyang|by|2116@bzh|滨州|BIK|binzhou|bz|2117@chd|巢湖东|GUH|chaohudong|chd|2118@cji|从江|KNW|congjiang|cj|2119@clh|长临河|FVH|changlinhe|clh|2120@cln|茶陵南|CNG|chalingnan|cln|2121@cqq|长庆桥|CQJ|changqingqiao|cqq|2122@csb|长寿北|COW|changshoubei|csb|2123@csh|潮汕|CBQ|chaoshan|cs|2124@cwu|长武|CWY|changwu|cw|2125@cxi|长兴|CBH|changxing|cx|2126@cya|长阳|CYN|changyang|cy|2127@cya|潮阳|CNQ|chaoyang|cy|2128@dad|东安东|DCZ|dongandong|dad|2129@ddh|东戴河|RDD|dongdaihe|ddh|2130@deh|东二道河|DRB|dongerdaohe|dedh|2131@dgu|东莞|RTQ|dongguan|dg|2132@dju|大苴|DIM|daju|dj|2133@dli|大荔|DNY|dali|dl|2134@dqg|大青沟|DSD|daqinggou|dqg|2135@dqi|德清|DRH|deqing|dq|2136@dsn|大石头南|DAL|dashitounan|dstn|2137@dtx|大通西|DTO|datongxi|dtx|2138@dxi|德兴|DWG|dexing|dx|2139@dxs|丹霞山|IRQ|danxiashan|dxs|2140@dyb|大冶北|DBN|dayebei|dyb|2141@dyd|都匀东|KJW|duyundong|dyd|2142@dyn|东营南|DOK|dongyingnan|dyn|2143@dyu|大余|DYG|dayu|dy|2144@dzd|定州东|DOP|dingzhoudong|dzd|2145@ems|峨眉山|IXW|emeishan|ems|2146@ezd|鄂州东|EFN|ezhoudong|ezd|2147@fcb|防城港北|FBZ|fangchenggangbei|fcgb|2148@fcd|凤城东|FDT|fengchengdong|fcd|2149@fch|富川|FDZ|fuchuan|fc|2150@fdu|丰都|FUW|fengdu|fd|2151@flb|涪陵北|FEW|fulingbei|flb|2152@fyu|抚远|FYB|fuyuan|fy|2153@fzd|抚州东|FDG|fuzhoudong|fzd|2154@fzh|抚州|FZG|fuzhou|fz|2155@gan|高安|GCG|gaoan|ga|2156@gan|广安南|VUW|guangannan|gan|2157@gbd|高碑店东|GMP|gaobeidiandong|gbdd|2158@gch|恭城|GCZ|gongcheng|gc|2159@gdb|贵定北|FMW|guidingbei|gdb|2160@gdn|葛店南|GNN|gediannan|gdn|2161@gdx|贵定县|KIW|guidingxian|gdx|2162@ghb|广汉北|GVW|guanghanbei|ghb|2163@gju|革居|GEM|geju|gj|2164@gmc|光明城|IMQ|guangmingcheng|gmc|2165@gni|广宁|FBQ|guangning|gn|2166@gpi|桂平|GAZ|guiping|gp|2167@gpz|弓棚子|GPT|gongpengzi|gpz|2168@gtb|古田北|GBS|gutianbei|gtb|2169@gtb|广通北|GPM|guangtongbei|gtb|2170@gtn|高台南|GAJ|gaotainan|gtn|2171@gyb|贵阳北|KQW|guiyangbei|gyb|2172@gyx|高邑西|GNP|gaoyixi|gyx|2173@han|惠安|HNS|huian|ha|2174@hbd|鹤壁东|HFF|hebidong|hbd|2175@hcg|寒葱沟|HKB|hanconggou|hcg|2176@hch|珲春|HUL|hunchun|hch|2177@hdd|邯郸东|HPP|handandong|hdd|2178@hdo|惠东|KDQ|huidong|hd|2179@hdx|海东西|HDO|haidongxi|hdx|2180@hdx|洪洞西|HTV|hongtongxi|hdx|2181@heb|哈尔滨北|HTB|haerbinbei|hebb|2182@hfc|合肥北城|COH|hefeibeicheng|hfbc|2183@hfn|合肥南|ENH|hefeinan|hfn|2184@hga|黄冈|KGN|huanggang|hg|2185@hgd|黄冈东|KAN|huanggangdong|hgd|2186@hgd|横沟桥东|HNN|henggouqiaodong|hgqd|2187@hgx|黄冈西|KXN|huanggangxi|hgx|2188@hhe|洪河|HPB|honghe|hh|2189@hhn|怀化南|KAQ|huaihuanan|hhn|2190@hhq|黄河景区|HCF|huanghejingqu|hhjq|2191@hhu|花湖|KHN|huahu|hh|2192@hji|怀集|FAQ|huaiji|hj|2193@hkb|河口北|HBM|hekoubei|hkb|2194@hme|鲘门|KMQ|houmen|hm|2195@hme|虎门|IUQ|humen|hm|2196@hmx|侯马西|HPV|houmaxi|hmx|2197@hna|衡南|HNG|hengnan|hn|2198@hnd|淮南东|HOH|huainandong|hnd|2199@hpu|合浦|HVZ|hepu|hp|2200@hqi|霍邱|FBH|huoqiu|hq|2201@hrd|怀仁东|HFV|huairendong|hrd|2202@hrd|华容东|HPN|huarongdong|hrd|2203@hrn|华容南|KRN|huarongnan|hrn|2204@hsb|黄石北|KSN|huangshibei|hsb|2205@hsb|黄山北|NYH|huangshanbei|hsb|2206@hsd|贺胜桥东|HLN|heshengqiaodong|hsqd|2207@hsh|和硕|VUR|heshuo|hs|2208@hsn|花山南|KNN|huashannan|hsn|2209@hyb|海阳北|HEK|haiyangbei|hyb|2210@hzd|霍州东|HWV|huozhoudong|hzd|2211@hzn|惠州南|KNQ|huizhounan|hzn|2212@jch|泾川|JAJ|jingchuan|jc|2213@jde|旌德|NSH|jingde|jd|2214@jhx|蛟河西|JOL|jiaohexi|jhx|2215@jlb|军粮城北|JMP|junliangchengbei|jlcb|2216@jle|将乐|JLS|jiangle|jl|2217@jlh|贾鲁河|JLF|jialuhe|jlh|2218@jmb|即墨北|JVK|jimobei|jmb|2219@jnb|建宁县北|JCS|jianningxianbei|jnxb|2220@jni|江宁|JJH|jiangning|jn|2221@jox|建瓯西|JUS|jianouxi|jox|2222@jqn|酒泉南|JNJ|jiuquannan|jqn|2223@jrx|句容西|JWH|jurongxi|jrx|2224@jsh|建水|JSM|jianshui|js|2225@jss|界首市|JUN|jieshoushi|jss|2226@jxb|绩溪北|NRH|jixibei|jxb|2227@jxd|介休东|JDV|jiexiudong|jxd|2228@jxi|泾县|LOH|jingxian|jx|2229@jxn|进贤南|JXG|jinxiannan|jxn|2230@jyn|嘉峪关南|JBJ|jiayuguannan|jygn|2231@jzh|晋中|JZV|jinzhong|jz|2232@kln|凯里南|QKW|kailinan|kln|2233@klu|库伦|KLD|kulun|kl|2234@kta|葵潭|KTQ|kuitan|kt|2235@kya|开阳|KVW|kaiyang|ky|2236@lbb|来宾北|UCZ|laibinbei|lbb|2237@lbi|灵璧|GMH|lingbi|lb|2238@lby|绿博园|LCF|lvboyuan|lby|2239@lch|罗城|VCZ|luocheng|lc|2240@lch|陵城|LGK|lingcheng|lc|2241@ldb|龙洞堡|FVW|longdongbao|ldb|2242@ldn|乐都南|LVO|ledunan|ldn|2243@ldn|娄底南|UOQ|loudinan|ldn|2244@ldy|离堆公园|INW|liduigongyuan|ldgy|2245@lfe|陆丰|LLQ|lufeng|lf|2246@lfn|禄丰南|LQM|lufengnan|lfn|2247@lfx|临汾西|LXV|linfenxi|lfx|2248@lhe|滦河|UDP|luanhe|lh|2249@lhx|漯河西|LBN|luohexi|lhx|2250@ljd|罗江东|IKW|luojiangdong|ljd|2251@ljn|利津南|LNK|lijinnan|ljn|2252@llb|龙里北|KFW|longlibei|llb|2253@lld|醴陵东|UKQ|lilingdong|lld|2254@lqu|礼泉|LGY|liquan|lq|2255@lsd|灵石东|UDV|lingshidong|lsd|2256@lsh|乐山|IVW|leshan|ls|2257@lsh|龙市|LAG|longshi|sh|2258@lsh|溧水|LDH|lishui|ls|2259@lxb|莱西北|LBK|laixibei|lxb|2260@lya|溧阳|LEH|liyang|ly|2261@lyi|临邑|LUK|linyi|ly|2262@lyn|柳园南|LNR|liuyuannan|lyn|2263@lzb|鹿寨北|LSZ|luzhaibei|lzb|2264@lzn|临泽南|LDJ|linzenan|lzn|2265@mgd|明港东|MDN|minggangdong|mgd|2266@mhn|民和南|MNO|minhenan|mhn|2267@mla|马兰|MLR|malan|ml|2268@mle|民乐|MBJ|minle|ml|2269@mns|玛纳斯|MSR|manasi|mns|2270@mpi|牟平|MBK|muping|mp|2271@mqb|闽清北|MBS|minqingbei|mqb|2272@msd|眉山东|IUW|meishandong|msd|2273@msh|庙山|MSN|miaoshan|ms|2274@myu|门源|MYO|menyuan|my|2275@mzb|蒙自北|MBM|mengzibei|mzb|2276@mzi|蒙自|MZM|mengzi|mz|2277@nch|南城|NDG|nancheng|nc|2278@ncx|南昌西|NXG|nanchangxi|ncx|2279@nfb|南芬北|NUT|nanfenbei|nfb|2280@nfe|南丰|NFG|nanfeng|nf|2281@nhd|南湖东|NDN|nanhudong|nhd|2282@nji|南江|FIW|nanjiang|nj|2283@njk|南江口|NDQ|nanjiangkou|nj|2284@nli|南陵|LLH|nanling|nl|2285@nmu|尼木|NMO|nimu|nm|2286@nnd|南宁东|NFZ|nanningdong|nnd|2287@npb|南平北|NBS|nanpingbei|npb|2288@nxi|南雄|NCQ|nanxiong|nx|2289@nyz|南阳寨|NYF|nanyangzhai|nyz|2290@pan|普安|PAN|puan|pa|2291@pbi|屏边|PBM|pingbian|pb|2292@pdi|普定|PGW|puding|pd|2293@pdu|平度|PAK|pingdu|pd|2294@pni|普宁|PEQ|puning|pn|2295@pnn|平南南|PAZ|pingnannan|pn|2296@psb|彭山北|PPW|pengshanbei|psb|2297@psh|坪上|PSK|pingshang|ps|2298@pxb|萍乡北|PBG|pingxiangbei|pxb|2299@pyc|平遥古城|PDV|pingyaogucheng|pygc|2300@pzh|彭州|PMW|pengzhou|pz|2301@qbd|青白江东|QFW|qingbaijiangdong|qbjd|2302@qdb|青岛北|QHK|qingdaobei|qdb|2303@qdo|祁东|QMQ|qidong|qd|2304@qfe|前锋|QFB|qianfeng|qf|2305@qli|青莲|QEW|qinglian|ql|2306@qqn|齐齐哈尔南|QNB|qiqihaernan|qqhen|2307@qsb|清水北|QEJ|qingshuibei|qsb|2308@qsh|青神|QVW|qingshen|qs|2309@qsh|岐山|QAY|qishan|qs|2310@qsh|庆盛|QSQ|qingsheng|qs|2311@qsx|曲水县|QSO|qushuixian|qsx|2312@qxd|祁县东|QGV|qixiandong|qxd|2313@qxi|乾县|QBY|qianxian|qx|2314@qya|祁阳|QWQ|qiyang|qy|2315@qzn|全州南|QNZ|quanzhounan|qzn|2316@rbu|仁布|RUO|renbu|rb|2317@rch|荣成|RCK|rongcheng|rc|2318@rdo|如东|RIH|rudong|rd|2319@rji|榕江|RVW|rongjiang|rj|2320@rkz|日喀则|RKO|rikaze|rkz|2321@rpi|饶平|RVQ|raoping|rp|2322@scl|宋城路|SFF|songchenglu|scl|2323@sdx|三都县|KKW|sanduxian|sdx|2324@she|商河|SOK|shanghe|sh|2325@sho|泗洪|GQH|sihong|sh|2326@sjn|三江南|SWZ|sanjiangnan|sjn|2327@sjz|三井子|OJT|sanjingzi|sjz|2328@slc|双流机场|IPW|shuangliujichang|sljc|2329@slx|双流西|IQW|shuangliuxi|slx|2330@smb|三明北|SHS|sanmingbei|smb|2331@spd|山坡东|SBN|shanpodong|spd|2332@sqi|沈丘|SQN|shenqiu|sq|2333@ssb|鄯善北|SMR|shanshanbei|ssb|2334@ssn|三水南|RNQ|sanshuinan|ssn|2335@ssn|韶山南|INQ|shaoshannan|ssn|2336@ssu|三穗|QHW|sansui|ss|2337@swe|汕尾|OGQ|shanwei|sw|2338@sxb|歙县北|NPH|shexianbei|sxb|2339@sxb|绍兴北|SLH|shaoxingbei|sxb|2340@sxi|始兴|IPQ|shixing|sx|2341@sxi|泗县|GPH|sixian|sx|2342@sya|泗阳|MPH|siyang|sy|2343@syb|邵阳北|OVQ|shaoyangbei|syb|2344@syb|上虞北|SSH|shangyubei|syb|2345@syb|松原北|OCT|songyuanbei|syb|2346@syi|山阴|SNV|shanyin|sy|2347@syn|沈阳南|SOT|shenyangnan|syn|2348@szb|深圳北|IOQ|shenzhenbei|szb|2349@szh|神州|SRQ|shenzhou|sz|2350@szs|深圳坪山|IFQ|shenzhenpingshan|szps|2351@szs|石嘴山|QQJ|shizuishan|szs|2352@szx|石柱县|OSW|shizhuxian|szx|2353@tcb|桃村北|TOK|taocunbei|tcb|2354@tdd|土地堂东|TTN|tuditangdong|tdtd|2355@tgx|太谷西|TIV|taiguxi|tgx|2356@tha|吐哈|THR|tuha|th|2357@tha|通海|TAM|tonghai|th|2358@thx|通化县|TXL|tonghuaxian|thx|2359@tlb|吐鲁番北|TAR|tulufanbei|tlfb|2360@tlb|铜陵北|KXH|tonglingbei|tlb|2361@tni|泰宁|TNS|taining|tn|2362@trn|铜仁南|TNW|tongrennan|trn|2363@txh|汤逊湖|THN|tangxunhu|txh|2364@txi|藤县|TAZ|tengxian|tx|2365@tyn|太原南|TNV|taiyuannan|tyn|2366@tyx|通远堡西|TST|tongyuanpuxi|typx|2367@wdd|文登东|WGK|wendengdong|wdd|2368@wfs|五府山|WFG|wufushan|wfs|2369@whb|威虎岭北|WBL|weihulingbei|whlb|2370@whb|威海北|WHK|weihaibei|whb|2371@wld|五龙背东|WMT|wulongbeidong|wlbd|2372@wln|乌龙泉南|WFN|wulongquannan|wlqn|2373@wns|五女山|WET|wunvshan|wns|2374@wwe|无为|IIH|wuwei|ww|2375@wws|瓦屋山|WAH|wawushan|wws|2376@wxx|闻喜西|WOV|wenxixi|wxx|2377@wyb|武夷山北|WBS|wuyishanbei|wysb|2378@wyd|武夷山东|WCS|wuyishandong|wysd|2379@wyu|婺源|WYG|wuyuan|wy|2380@wzh|武陟|WIF|wuzhi|wz|2381@wzn|梧州南|WBZ|wuzhounan|wzn|2382@xab|兴安北|XDZ|xinganbei|xab|2383@xcd|许昌东|XVF|xuchangdong|xcd|2384@xch|项城|ERN|xiangcheng|xc|2385@xdd|新都东|EWW|xindudong|xdd|2386@xfe|西丰|XFT|xifeng|xf|2387@xfx|襄汾西|XTV|xiangfenxi|xfx|2388@xgb|孝感北|XJN|xiaoganbei|xgb|2389@xhn|新化南|EJQ|xinhuanan|xhn|2390@xhx|新晃西|EWQ|xinhuangxi|xhx|2391@xji|新津|IRW|xinjin|xj|2392@xjn|新津南|ITW|xinjinnan|xjn|2393@xnd|咸宁东|XKN|xianningdong|xnd|2394@xnn|咸宁南|UNN|xianningnan|xnn|2395@xpn|溆浦南|EMQ|xupunan|xpn|2396@xtb|湘潭北|EDQ|xiangtanbei|xtb|2397@xtd|邢台东|EDP|xingtaidong|xtd|2398@xwx|修武西|EXF|xiuwuxi|xwx|2399@xxd|新乡东|EGF|xinxiangdong|xxd|2400@xyb|新余北|XBG|xinyubei|xyb|2401@xyc|西阳村|XQF|xiyangcun|xyc|2402@xyd|信阳东|OYN|xinyangdong|xyd|2403@xyd|咸阳秦都|XOY|xianyangqindu|xyqd|2404@xyo|仙游|XWS|xianyou|xy|2405@ybl|迎宾路|YFW|yingbinlu|ybl|2406@ycb|运城北|ABV|yunchengbei|ycb|2407@ych|宜春|YEG|yichun|yc|2408@ych|岳池|AWW|yuechi|yc|2409@yfd|云浮东|IXQ|yunfudong|yfd|2410@yfn|永福南|YBZ|yongfunan|yfn|2411@yge|雨格|VTM|yuge|yg|2412@yhe|洋河|GTH|yanghe|yh|2413@yjb|永济北|AJV|yongjibei|yjb|2414@yjp|于家堡|YKP|yujiapu|yjp|2415@yjx|延吉西|YXL|yanjixi|yjx|2416@ylh|运粮河|YEF|yunlianghe|ylh|2417@yli|炎陵|YAG|yanling|yl|2418@yln|杨陵南|YEY|yanglingnan|yln|2419@yna|郁南|YKQ|yunan|yn|2420@ysh|永寿|ASY|yongshou|ys|2421@ysn|玉山南|YGG|yushannan|ysn|2422@yta|永泰|YTS|yongtai|yt|2423@ytb|鹰潭北|YKG|yingtanbei|ytb|2424@ytn|烟台南|YLK|yantainan|ytn|2425@yxi|尤溪|YXS|youxi|yx|2426@yxi|云霄|YBS|yunxiao|yx|2427@yxi|宜兴|YUH|yixing|yx|2428@yxi|阳信|YVK|yangxin|yx|2429@yxi|应县|YZV|yingxian|yx|2430@yxn|攸县南|YXG|youxiannan|yxn|2431@yyb|余姚北|CTH|yuyaobei|yyb|2432@zan|诏安|ZDS|zhaoan|za|2433@zdc|正定机场|ZHP|zhengdingjichang|zdjc|2434@zfd|纸坊东|ZMN|zhifangdong|zfd|2435@zhu|昭化|ZHW|zhaohua|zhu|2436@zji|芷江|ZPQ|zhijiang|zj|2437@zji|织金|IZW|zhijin|zj|2438@zli|左岭|ZSN|zuoling|zl|2439@zmx|驻马店西|ZLN|zhumadianxi|zmdx|2440@zpu|漳浦|ZCS|zhangpu|zp|2441@zqd|肇庆东|FCQ|zhaoqingdong|zqd|2442@zqi|庄桥|ZQH|zhuangqiao|zq|2443@zsx|钟山西|ZAZ|zhongshanxi|zsx|2444@zyx|张掖西|ZEJ|zhangyexi|zyx|2445@zzd|涿州东|ZAP|zhuozhoudong|zzd|2446@zzd|卓资东|ZDC|zhuozidong|zzd|2447@zzd|郑州东|ZAF|zhengzhoudong|zzd|2448';

	public static function getName($val = ''){
		if(empty($val)){ return false; }
		$pattern  = '/'.$val.'\|([A-Z]+)\|/';
		preg_match($pattern, self::STATICON_NAMES, $matches);
		return isset($matches[1]) ? $matches[1] : false;
	}
}
// 地名处理类 End
?>
<!DOCTYPE html>
<html>
<head>
	<title>火车票检漏脚本 - By v1.0</title>
	<meta charset="UTF-8" />
	<style type="text/css">
		*{margin:0;padding:0;}
		body{font-size:12px; font-family: "Helvetica Neue", "Hiragino Sans GB", "Segoe UI", "Microsoft Yahei", "微软雅黑", Tahoma, Arial, STHeiti, sans-serif; }
		a{text-decoration:none; color:#459ae9; margin:0 5px;}
		.hidden{display:none;}
		.wrap{width:980px; height:auto; margin:0 auto;}

		div.title{height:100px; line-height:100px; text-align:center; margin-top:20px;}
		div.title h1{font-size:24px;}

		div.block{width:100%; margin-top:20px;}
		div.block h2{font-size:14px; line-height:32px; padding-left:10px;}
		div.block h2 p{float:right; padding-right:10px;}
		div.block h2 p span{font-weight:200;padding-left:5px;}
		div.block table{width:100%;border-collapse:collapse;border-spacing:0;}
		div.block table tr td,div.block table tr th{border:1px solid #ddd; padding:8px 10px;background-color:#eee;}
		.input-txt{width:100%; height:22px; line-height:22px;}

		div.config_block table thead th:nth-of-type(1){width:150px; text-align:center;}
		div.config_block table thead th:nth-of-type(3){width:145px; text-align:center;}
		div.config_block table tbody tr td:nth-of-type(1){text-align:right;}

		div.api_block table thead th:nth-of-type(1){width:20px; text-align:center;}
		div.api_block table tbody tr td:nth-of-type(1){text-align:center;}

		div.api_block table thead th:nth-of-type(2){width:30px; text-align:center;}
		div.api_block table tbody tr td:nth-of-type(2){text-align:center;}

		div.api_block table thead th:nth-of-type(3){ text-align:center;}
		div.api_block table tbody tr td:nth-of-type(3){text-align:left;}

		div.api_block table thead th:nth-of-type(5),div.api_block table thead th:nth-of-type(6){text-align:center;}
		div.api_block table tbody tr td:nth-of-type(5){text-align:center;}
		div.api_block table tbody tr td:nth-of-type(6){text-align:center;}

		div.api_block table tbody tr td:nth-of-type(5) > strong{font-weight:400; font-size:16px;}

		div.api_block table thead th:nth-of-type(7){ text-align:center;}
		div.api_block table tbody tr td:nth-of-type(7){text-align:center;}

		footer{width:100%; height:100px; line-height:100px; text-align:center;}
		.dosubmit{background-color:#ffb000; padding:6px 20px; text-shadow:1px 1px 0 #cf7000; border:solid 1px #e77c00; font-family: "Microsoft YaHei", SimSun, Tahoma, Verdana, Arial, sans-serif; font-size:16px; color:#FFF; cursor:pointer;font-weight:bold;letter-spacing:0.4em;text-indent:0.4em;box-shadow: 0 1px 0 rgba(95,50,0,0.7);border-radius:3px;}

		/* ======================================= */
		.box{width:52%; height:55%; position:fixed; left:50%; top:50%; border:5px solid #ccc; background-color:#FFF; margin-top:-16%; margin-left:-26%;overflow:hidden; display:none;}
		.box-title{width:100%; height:30px; line-height: 30px;background-color:#3385ff;}
		.box-title span{foont-size:14px; font-weight:bold; color:#FFF; padding-left:10px; float:left;}
		.box-title strong{foont-size:14px; font-weight:bold; color:#FFF; padding-right:5px; float:right;}
		.box-title strong a{color:#FFF;}
		.box-content{width:100%; height:100%;}
		.box-textarea{width:100%; height:100%; font-size:12px; line-height:16px;}
	</style>

</head>

<body>

	<div class="wrap">
		<header>
			<div class="title"><h1>火车票检漏脚本 - By v1.1</h1></div>
		</header>
		<content>

			<div class="block api_block">
				<h2>查询信息<br />
					出行时间：<?php echo $date; ?><br />
					出发地：<?php echo $from; ?><br />
					目的地：<?php echo $to; ?><br />
				</h2>
			</div>

			<!-- API 区域 -->
			<div class="block api_block">
				<h2>可购买车次列表 <p></h2>
				<table>
					<thead>
					<tr>
						<th>序</th>
						<th>车次</th>
						<th>行程</th>
						<th>发车时间</th>
						<th>硬卧</th>
						<th>硬座</th>
						<th>二等座</th>
						<th>一等座</th>
						<th>操作</th>
					</tr>
					</thead>
					<tbody>
					<?php if(M::$buyArr): foreach(M::$buyArr as $item): ?>
					<tr>
						<td></td>
						<td><?php echo U::getValue($item,'station_train_code');?></td>
						<td><?php echo U::getValue($item,'from_station_name'),' == ',U::getValue($item,'to_station_name'); ?></td>
						<td><?php echo U::getValue($item,'start_time');?></td>
						<td><?php echo U::getValue($item,'yw_num');?></td>
						<td><?php echo U::getValue($item,'yz_num');?></td>
						<td><?php echo U::getValue($item,'ze_num');?></td>
						<td><?php echo U::getValue($item,'zy_num');?></td>
						<td><a href="javascript:;" onclick="mySubmitForm('<?php echo U::getValue($item,'from_station_name');?>','<?php echo U::getValue($item,'to_station_name');?>','<?php echo U::getValue($item,'from_station_telecode');?>','<?php echo U::getValue($item,'to_station_telecode');?>','<?php echo $date;?>');">立即购买</a></td>
					</tr>
					<?php endforeach; endif; ?>
					</tbody>
				</table>
			</div>
			<!-- API 区块 End -->
		</content>

		<footer>
			<p><strong>©</strong><a href="https://www.56br.com/" target="_blank">Wanglele</a><span>版权所有</span></p>
		</footer>
	</div>

<form method="post" name="MyForm" id="MyForm" class="MyForm" action="https://kyfw.12306.cn/otn/leftTicket/init" target="_blank">
		<input type="hidden" id="from_station_name" name="leftTicketDTO.from_station_name" value="北京" />
		<input type="hidden" id="to_station_name" name="leftTicketDTO.to_station_name" value="襄阳" />
		<input type="hidden" id="from_station" name="leftTicketDTO.from_station" value="BJP" />
		<input type="hidden" id="to_station" name="leftTicketDTO.to_station" value="XFN" />
		<input type="hidden" id="train_date" name="leftTicketDTO.train_date" value="2016-02-04" />

		<input type="hidden" name="back_train_date" value="" />
		<input type="hidden" name="flag" value="wf" />
		<input type="hidden" name="purpose_code" value="ADULT" />
		<input type="hidden" name="pre_step_flag" value="index" />
</form>

	<script type="text/javascript">
		function g($str){ return document.querySelector($str); }

		function mySubmitForm(from_station_name,to_station_name,from_station,to_station,train_date){
			g("#from_station_name").value = from_station_name;
			g("#to_station_name").value = to_station_name;
			g("#from_station").value = from_station;
			g("#to_station").value = to_station;
			g("#train_date").value = train_date;

			g("#MyForm").submit();
		}

	</script>

</body>
</html>

