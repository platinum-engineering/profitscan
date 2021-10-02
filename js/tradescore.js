FD = (FD) ? FD : '/'; // '/mn/tradescore/'; // if defolt then just  "/" 
errTry = 0;
errTryLimit = 5;


$(document).ready(function () { 
	//console.log('trade score : run ');	
	// first time running
	let wallet = getWallet();
	if (wallet.length>0) {
		document.querySelector('#id').value = wallet;
		walletAction('init');
	}
});

function getWallet() {
	let pathArray = document.location.pathname.split(FD)[1].split('/');
		wallet = pathArray[0];
	return wallet;
}

function readRespond(respond, wallet) {
	
	let actions = {
		title : 'Error pls try later.',
	};
	
	if (respond.hasOwnProperty('title')) actions.title = respond.title;		// Пришел ответ 
	
	if (respond.hasOwnProperty('csv')) {
		document.querySelector('#button_csv_light').href = FD+'api/doReportWeb.php?wallet='+wallet+'&mode=csv&ts='+Math.floor(Date.now() / 1000);
		document.querySelector('#button_csv_full').href = FD+'api/doReportWeb.php?wallet='+wallet+'&mode=csvfull&ts='+Math.floor(Date.now() / 1000);
	}
	
	document.querySelector('#actions').innerHTML = Object.values(actions).join('');	
	
	if (respond.monthly) {
		//console.log(respond.monthly);
		let years = Object.keys(respond.monthly[0]);
		delete years[years.length-1];
			
		document.querySelector('#monthly').innerHTML = obj2table({tab: respond.monthly, cols: 'Month,'+years.join(','), headHtml: 'style="width:300px;"'});			
	}
	
	if (respond.rating) {
		
		// modify table html
		respond.rating.forEach((row, i) => {	
			let tabWallet = respond.rating[i].wallet,
				shortWallet = tabWallet.substring(0, 4) + '...' + tabWallet.substring(tabWallet.length - 4);
			if (tabWallet != '-') {
				
				respond.rating[i].profEthTotal = Math.round(respond.rating[i].profEthTotal);
				respond.rating[i].tradeEth = Math.round(respond.rating[i].tradeEth);
				
				respond.rating[i].txLastAt = unix2date(respond.rating[i].txLastAt).split(' ')[0];
				respond.rating[i].stateAt = unix2date(respond.rating[i].stateAt).split(' ')[0];
				respond.rating[i].wallet = '<a href="' + FD + tabWallet+'/">'+shortWallet+'</a>';
				
				// If tabWallet == wallet
				if (tabWallet == wallet) {
					// then hilglight the row in bold
					for (col in respond.rating[i]) respond.rating[i][col] = '<h4>'+respond.rating[i][col]+'</h4>';
				}
				
			}
		});
		// 
		document.querySelector('#rating').innerHTML = obj2table({tab: respond.rating, cols: {
			rank 			: 'Rank',
			wallet 			: 'Wallet',
			txTotal 		: 'Total<br>transactions',
			txSales 		: 'Trade<br>sales(fixing)',
			tradeEth 		: 'Sale<br>turnover (eth)',
			profEthTotal 	: 'Total<br>profit (eth)',
			txLastAt 		: 'Last tx<br>at date',
			stateAt 		: 'Report<br>at date',
		}});
		
	}
	
	if (respond.summary) {
		
		// Подсчитываем тут доходность в процентах
		if (respond.summary.profEthTotal && respond.summary.tradeEth) {
			respond.summary.avEfficiency = 0;
			if (respond.summary.tradeEth !== respond.summary.profEthTotal) respond.summary.avEfficiency = (respond.summary.profEthTotal / ( (respond.summary.tradeEth - respond.summary.profEthTotal) / 100 )).toFixed(2);		
		}
		
		let scoreNames = {
				// COl 1
				summary_col_1 : {
					txTotal			: ['Total transactions&nbsp;:', ''],
					tradeEth		: ['Sale turnover&nbsp;:', ' ETH'],
					profEth			: ['Profit Fixed&nbsp;:', ' ETH'],
					profEthOpen		: ['Profit Open &nbsp;:', ' ETH'],		
					balanceEth		: ['Balance in ETH&nbsp;:', ' ETH'],							
				},
				// COl 2
				summary_col_2 : {
					lastBuyAt		: ['Last Trade date&nbsp;:', ''],
					lastSaleAt		: ['Last Profit date&nbsp;:', ''],
					txSales 		: ['Trade sales(fixing)&nbsp;:', ''],
					openBuys		: ['Open Trades Count&nbsp;:', ''],
					fee				: ['Total fee&nbsp;:', ' ETH'],			
				},				
				// COl 3
				summary_col_3 : {
					avEfficiency	: ['Total Efficiency&nbsp;:', ' %'],
					openTokens		: ['Total Open Token Amount&nbsp;:', ''],	
					tradeEthNoRate	: ['Sale Turnover without profit score(ETH)&nbsp;:', ' ETH'],
					profEthOut		: ['Profit Out&nbsp;:', ' ETH'],
					profEthTotal	: ['Total Profit&nbsp;:', ' ETH'],
				},				
				summary_byCoinType : {
					profEthTotal	: ['Total Profit&nbsp;:', ' ETH'],
					row0				: ['---------------------', ''],	
					profEth				: ['Profit Fixed&nbsp;:', ' ETH'],
					profEth_top10		: ['In (top10)&nbsp;:', 'ETH'],
					profEth_top100		: ['In (top100)&nbsp;:', 'ETH'],
					profEth_other		: ['In (other)&nbsp;:', 'ETH'],
					row1				: ['---------------------', ''],	
					profEthOut			: ['Profit Out&nbsp;:', ' ETH'],
					profEthOut_top10	: ['In (top10)&nbsp;:', ' ETH'],
					profEthOut_top100	: ['In (top100)&nbsp;:', ' ETH'],
					profEthOut_other	: ['In (other)&nbsp;:', ' ETH'],
					row2				: ['---------------------', ''],	
					profEthOpen			: ['Profit Open&nbsp;:', ' ETH'],					
					profEthOpen_top10	: ['In (top10)&nbsp;:', ' ETH'],	
					profEthOpen_top100	: ['In (top100)&nbsp;:', ' ETH'],	
					profEthOpen_other	: ['In (other)&nbsp;:', ' ETH'],	
					
				}
				
				// profEthPer	:	['Average deal profit:',' %'],
				// openEth:'Profit in open deals(ETH):',
			};

		// Дорабатываем суммы профита по прочим не топовым монетам в отчете
		'profEth,profEthOut,profEthOpen'.split(',').forEach((col) => { 
			if (respond.summary.hasOwnProperty(col+'_top10')) {
				respond.summary[col+'_other'] = (respond.summary[col] - respond.summary[col+'_top10'] - respond.summary[col+'_top100']).toFixed(2);
			}			
		});



		for (scoreCol in scoreNames) {

			let summaryCol = [];
			
			for (scoreKey in scoreNames[scoreCol]) {

				let scoreName = scoreNames[scoreCol][scoreKey],
					valueStyle = 'dark',
					headColor = 'dark',
					value = (respond.summary.hasOwnProperty(scoreKey)) ? respond.summary[scoreKey] : '-';

				if (isNumeric(value)) {
					// Coulorung negative numbers in red
					if (value !== '-' && scoreName[1] !=='' ) {
						valueStyle = (value < 0) ? 'danger' : 'success';
					}
						
					if (scoreKey === 'tradeEthNoRate') {
						valueStyle = 'dark';
						if (value>0) {
							headColor = 'danger';
							valueStyle = 'danger';
						}
					}   
					if (['lastBuyAt','lastSaleAt'].indexOf(scoreKey) !== -1 && value != '-') value = unix2date(value);					
				}

				if (scoreKey === 'profEthTotal') headColor = 'info';

				// Collect html for column row border-top 
				summaryCol.push('<dt class="col-5 text-'+headColor+'">'+scoreName[0]+'</dt><dd class="col-7 text-'+valueStyle+'">'+value+scoreName[1]+'</dd>');

			}
			
			// Inject html for current column
			document.querySelector('#'+scoreCol).innerHTML = summaryCol.join('');					
		
		}

		document.querySelector('#section-score').style.display = '';
		
		if (respond.summary.hasOwnProperty('profEthTotal')) document.querySelector('#score').innerHTML = Math.round(respond.summary.profEthTotal);
		if (respond.members && respond.rank)  {
			document.querySelector('#members').innerHTML = respond.members;
			document.querySelector('#rank').innerHTML = respond.rank;
			
		let degenRateList = 'peasant,ape,chad,degen,marnotaur'.split(','),
			degenStage = 1 / degenRateList.length,
			membersStage = (1 - respond.rank / respond.members),
			stepInRateList = Math.ceil(membersStage / degenStage),
			degenRateListHtmlArr=[];
		
			if (stepInRateList > 0) stepInRateList = stepInRateList -1;
		
			degenRateList.forEach((stage, step) => { 
				if (step != stepInRateList) {
					degenRateListHtmlArr.push('<li>'+stage+'</li>');
				} else {
					degenRateListHtmlArr.push('<li class="acolor"><strong style="font-size: larger;">'+stage+'</strong></li>');
				}
				
			});
			document.querySelector('#degen').innerHTML = degenRateListHtmlArr.join('');
		} 

	}
	
}

function walletCheck() {
	
	let wallet = document.querySelector('#id').value,
		reportUrl = document.location.origin + FD + wallet+'/';
	//console.log(wallet, ); // document.location
	document.location.href = reportUrl;

}

async function walletAction(mode) {

	let wallet = getWallet();	
			
	document.querySelector('#loading').innerHTML='<div class="spinner-border" role="status"><span class="sr-only">Loading...</span></div>';
	
	let init = await getApi(FD+'api/doReportWeb.php?wallet='+wallet+'&inHtml=1&mode='+mode);
	
	// to avoid looping , every time change mode to init after refresh or not (every time) 
	mode = 'init';
	
	if (init.hasOwnProperty('stop')) { 
		init.title = init.stop; 
		readRespond(init, wallet); 
		document.querySelector('#loading').innerHTML='done'; 
		return false;
	}
	
	if (init.hasOwnProperty('error') && errTry < errTryLimit) {
		errTry++;
		//await walletAction(mode);
		
		window.setTimeout(function () { 
			walletAction(mode);
		}, 5000);
		
	} else {
		errTry = 0;
		readRespond(init, wallet);
	}

	// if we have permit for next time clime for update wallet data
	if (init.hasOwnProperty('nextTurnInSec')) { 
		window.setTimeout(function () { 
			walletAction(mode);
		}, init.nextTurnInSec * 1000);	
	} else {
		document.querySelector('#loading').innerHTML='done';
	}

}

async function getApi(url){
	
	let result = { error:true };
	
	try {
		let api = await fetch(url);
		result = await api.json();
	} catch(err) {
		//console.log('getApi:err');
	}

	return result;
}

function obj2tableEmtyRow(row){
	let emptyObj = {};	
	for (col in row) emptyObj[col] = '';
	return emptyObj;
}

function obj2table(obj){
	let head = [],
		body = [];

	let set = {};

	if (Array.isArray(obj)) {
		set.tab = obj;
	} else {
		set = obj;
	}

	if (typeof set.cols === 'string') {
		let colNames = {};
		set.cols.split(',').forEach((col) => { 
			colNames[col]=col;
		});
		set.cols = colNames;
	}
	
	//console.log(set.cols);
	
	set.tab.forEach((rowObj, i) => { 
		//for (let objKey in obj) {
		let row=[];
		if (!set.cols) {
			// Строим все что есть в obj[objKey].d
			for (let rowKey in rowObj) {
				if (i === 0) head.push('<th>'+rowKey+'</th>');
				row.push('<td>'+rowObj[rowKey]+'</td>'); 		
			}			
		} else {
			// Строим только указанный заголовок
			for (let col in set.cols) {
				if (i === 0) head.push('<th>'+set.cols[col]+'</th>');
				val= (rowObj[col]) ? rowObj[col] : '';
				row.push('<td>'+val+'</td>');				
			}
		}
		
		trClass='';		
		// Если явно указан класс  
		if (rowObj.rowClass) trClass=' class="text-'+rowObj.rowClass+'"';
		
		body.push('<tr'+trClass+'>'+row.join('')+'</tr>');
	});
	
	let headHtml = (set.headHtml) ? set.headHtml : '';		
	 // style="width:300px;" 
	return '<table class="table" '+headHtml+'><thead><tr>'+head.join('')+'</tr></thead><tbody>'+body.join('')+'</tbody></table>';
}

function unix2date(unix_timestamp = false) {
	let date = (unix_timestamp) ? new Date(unix_timestamp * 1000) : new Date() ,
		year = date.getFullYear(),
		month = "0" + (date.getMonth()+1),
		day = "0" + date.getDate(),
		hours = "0" + date.getHours(),	// Hours part from the timestamp .substr(-2)
		minutes = "0" + date.getMinutes(),	// Minutes part from the timestamp
		seconds = "0" + date.getSeconds(),
		full = year + '-' + month.substr(-2) + '-' + day.substr(-2) + ' ' + hours.substr(-2) + ':' + minutes.substr(-2) + ':' + seconds.substr(-2);	// Seconds part from the timestamp

	return full;	// Will display time in 10:30:23 format
}

function isNumeric(str) {
  if (typeof str != "string") return false // we only process strings!  
  return !isNaN(str) && // use type coercion to parse the _entirety_ of the string (`parseFloat` alone does not do this)...
         !isNaN(parseFloat(str)) // ...and ensure strings of whitespace fail
}