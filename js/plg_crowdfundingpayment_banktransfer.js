jQuery(document).ready(function() {
	 
	jQuery("#js-register-bt").on("click", function(event){
		event.preventDefault();
		jQuery('#js-banktransfer-modal').modal("show");
	});
	
	jQuery("#js-btbtn-yes").on("click", function(event){
		event.preventDefault();
		
		var data = {
			pid: 	 		 jQuery(this).data("project-id"),
			amount: 		 jQuery(this).data("amount"),
			payment_service: "banktransfer"
		};
		
		jQuery.ajax({
			url: "index.php?option=com_crowdfunding&task=payments.preparePaymentAjax&format=raw",
			type: "POST",
			data: data,
			dataType: "text json",
			cache: false,
			beforeSend: function(response) {
				
				// Display ajax loading image
				jQuery("#js-banktransfer-ajax-loading").show();
				jQuery("#js-btbtn-yes").prop("disabled", true);
				jQuery("#js-btbtn-no").prop("disabled", true);
				
			},
			success: function(response) {
				
				// Hide ajax loading image
				jQuery("#js-banktransfer-ajax-loading").hide();
				
				// Hide the button
				jQuery("#js-register-bt").hide();
				
				// Set the information about transaction and show it.
				jQuery("#js-bt-alert").html(response.text).show();
				
				// Displa the button that points to next step
				if(response.success) {
					jQuery("#js-continue-bt").attr("href", response.data.return_url).show();
				} else {
					if(response.redirect_url) {
						setTimeout("location.href = '"+ response.redirect_url +"'", 1500);
					}
				}
				
				// Hide modal window
				jQuery('#js-banktransfer-modal').modal('hide')
			}
				
		});
		
	});
	
	jQuery("#js-btbtn-no").on("click", function(event){
		event.preventDefault();
		jQuery('#js-banktransfer-modal').modal('hide')
	});
});