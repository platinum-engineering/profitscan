// Парсит баланс страницу эзерскана в json 
// Нужен для ручной сверки балансов 
function ethGetBalance(){
	let tab=document.querySelector('#tb1'), //  table.table-responsive
		rows=tab.querySelectorAll('tr'),
		balance={},
		doubles=[];
	for (let i = 0; i < rows.length; ++i) {
		let td=rows[i].querySelectorAll('td'),
			key=td[2].innerText;
			let span = td[2].querySelector('span');
			if (span) {	// .hasAttribute('data-original-title')) {
				key=span.getAttribute('data-original-title');
				console.log(i,key);
			} 

		if (balance[key]) {
			doubles.push(key);
			key = key + '~'+doubles.length;
		} 
		balance[key]=td[3].innerText.replace(/[\s,%]/g, '')*1;
	}
	console.log(JSON.stringify(balance));
}