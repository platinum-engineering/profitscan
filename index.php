<?php define('FILE', __FILE__);     # Точка входа

require('config_sapiens.php');

$loadingHtml = '<div class="spinner-border"><span class="sr-only">Loading...</span></div>';
?> 
<!DOCTYPE html>
<html lang="en">
	<head>
		<meta charset="UTF-8">
		<meta name="viewport" content="width=device-width, initial-scale=1.0">
		<title>Trade profit report</title>
		<link rel="stylesheet" href="<?= FD ?>js/bootstrap.min.css"/>
		<script type="text/javascript" src="<?= FD ?>js/jquery-3.2.1.slim.min.js"></script>
		<script type="text/javascript" src="<?= FD ?>js/bootstrap.min.js"></script>	
		<script> let FD = '<?= FD ?>';</script>
		<script type="text/javascript" src="<?= FD ?>js/tradescore.js?cache=<?= date('Y-m-d_H_i') ?>"></script>
		<style>
			body {
				color: #ffc107;
				background-color: black;
			}
			.container {
				background-color: #1e1717;
			}
			.text-dark {
				color: #ffc107 !important;
			}
			#id {
				background-color: #ffc107;
			}
			.nav-tabs .nav-item.show .nav-link, .nav-tabs .nav-link.active {
				background-color: #ffc107 !important; 
				border-color: #ffc107 #ffc107 #ffc107;
				color: #1e1717 !important; 
			}
			.nav-tabs {
				border-bottom: 1px solid #ffc107 !important; 
			}
			.border { 
				border: 1px solid #ffc107 !important; 
			}
			.form-control {
				border: 1px solid #ffc107 !important; 
				color: #1e1717 !important; 
			}
			.btn-outline-secondary {
				color: #ffc107 !important; 
				border-color: #ffc107 !important;
			}
			.border, .btn-outline-secondary, .form-control {
				box-shadow: 0 0 10px #ffc107; 
			}
			a {
				color: #ffc107 !important; 
			}
			.acolor {
				color: #ffeb3b !important;
			}
			.table {
				color: #ffc107 !important;
			}
			.table td, .table th {
				border-top: 1px solid #ffc107 !important;
			}
			.border-top {
				border-top: 1px solid #ffc107 !important;
			}
		</style>
	</head>
	<body>
		<div class="container h-100 justify-content-center align-items-center">
		
			<!-- title header and wallet input section -->
			<div class="row justify-content-around">
				<div class="col-12 col-sm-11">	
					<div class="text-center p-3">
						<div id="loading" class="acolor"></div>
						<div id="actions">Input wallet and press check for profit calculation.</div>
					</div>
				</div>
				<div class="col-12 col-sm-11">					
					<div class="input-group">
					  <input type="text" class="form-control" placeholder="Ethereum account" id="id">
					  <div class="input-group-append">
						<button class="btn btn-outline-secondary" type="button" onclick="walletCheck()">Check</button>
					  </div>
					</div>
				</div>
			</div>
			
			<div id="section-score" style="display:none;">
			
				<!-- degen score section id="section-score"  -->
				<div class="row justify-content-around p-3">
					<div class="col-12 col-sm-5 col-md-3 border rounded p-1 m-1">
						<p class="text-center" style="margin: revert;">Your score:</p>
						<h2 class="text-center acolor" style="margin: revert;"><strong id="score">...</strong></h2>
					</div>
					<div class="col-12 col-sm-5 col-md-3 border rounded p-1 m-1">
						<ul class="list-unstyled text-center" style="margin: revert;" id="degen">
							...
						</ul>
					</div>
					<div class="col-12 col-sm-11 col-md-3 border rounded p-1 m-1">
						<div class="text-center">Your rank in liderboard:</div>
						<h2 class="text-center acolor" style="margin: revert;"><strong id="rank">...</strong></h2>
						<div class="text-center">out of <strong id="members">...</strong></div>
					</div>
				</div>

				<!-- share buttons id="section-share"  -->
				<div class="row justify-content-around p-3 h-100">
					<div class="col-12 col-md-5 border rounded p-1 m-1">
						<!--<h4 class="text-center"><strong>Share your rank on twitter to get your NFT </strong></h4>-->
						<!-- href="/mn/tradescore/init.php?wallet=0x26ef11bae97a5b57190bc85f6a16a75a745ad1ca&amp;mode=scv&amp;ts=<?= date('YmdHis') ?>" -->
						<a id="button_csv_full" class="btn btn-success btn-lg btn-block" type="button" target="_blank" href="#">CSV full file</a>
					</div>
					<div class="col-12 col-md-5 border rounded p-1 m-1">
						<div class="row justify-content-around">
							<div class="col-5 p-1 m-1 border rounded">	
								<h2 class="text-center acolor" style="margin: revert;">:)</h2>
							</div>
							<div class="col-5 border rounded p-1 m-1">					
								<p class="text-center" style="margin: revert;"><strong>Get your NFT 50 left out of 500</strong></p>
							</div>
						</div>
					</div>
				</div>

				<!-- buttons download csv and refresh id="section-buttons"  -->
				<div class="row justify-content-around p-3">
					<div class="col-4 border rounded p-1 m-1">
						<a id="button_csv_light" class="btn btn-success btn-lg btn-block" type="button" target="_blank" href="#">CSV lite file</a>
					</div>
					<div class="col-4 border rounded p-1 m-1">
						<button class="btn btn-danger btn-lg btn-block" onclick="walletAction('refresh')">Refresh</button>
					</div>
				</div>

				<!-- tabs: summary, mounth and rating tab -->
				<div class="row justify-content-around p-3">
					<div class="col-12 col-sm-11">	
						<ul class="nav nav-tabs" id="myTab" role="tablist">
						  <li class="nav-item">
							<a class="nav-link active" data-toggle="tab" href="#summary">Summary</a>
						  </li>
						  <li class="nav-item">
							<a class="nav-link" data-toggle="tab" href="#monthly">Monthly</a>
						  </li>
						  <li class="nav-item">
							<a class="nav-link" data-toggle="tab" href="#rating">Rating</a>
						  </li>
						  <li class="nav-item">
							<a class="nav-link" data-toggle="tab" href="#byCoinType">By Coin Type</a>
						  </li>
						</ul>

						<div class="tab-content p-3">
							<div class="tab-pane active" id="summary">
							<!-- for three summary colums -->
							
								<div class="row justify-content-around">
									<div class="col-12 col-sm-12 col-md-4">
										<dl class="row" id="summary_col_1">
											<!--
											<dt class="col-5">Total transactions:</dt><dd class="col-7 text-dark">1778</dd>
											<dt class="col-5">Sale turnover:</dt><dd class="col-7 text-success">16614.4500 ETH</dd>
											<dt class="col-5 text-dark">Profit Fixed:</dt><dd class="col-7 text-danger">-215.6219 ETH</dd>
											-->
											<?= $loadingHtml ?>
										</dl>
									</div>
									<div class="col-12 col-sm-12 col-md-4">
										<dl class="row" id="summary_col_2">
											<?= $loadingHtml ?>
										</dl>
									</div>
									<div class="col-12 col-sm-12 col-md-4">
										<dl class="row" id="summary_col_3">
											<?= $loadingHtml ?>
										</dl>
									</div>
								</div>							
						  
							</div>
							<div class="tab-pane" id="monthly"><?= $loadingHtml ?></div>
							<div class="tab-pane" id="byCoinType">
								<div class="row justify-content-around">
									<dl class="row" id="summary_byCoinType">
										<!--
										<dt class="col-5">Total transactions:</dt><dd class="col-7 text-dark">1778</dd>
										<dt class="col-5">Sale turnover:</dt><dd class="col-7 text-success">16614.4500 ETH</dd>
										<dt class="col-5 text-dark">Profit Fixed:</dt><dd class="col-7 text-danger">-215.6219 ETH</dd>
										-->
										<?= $loadingHtml ?>
									</dl>
								</div>
							</div>
							<div class="tab-pane" id="rating"><?= $loadingHtml ?></div>
						</div>	
					</div>
				</div>
				
			</div>
			
		</div>

	</body>
</html>