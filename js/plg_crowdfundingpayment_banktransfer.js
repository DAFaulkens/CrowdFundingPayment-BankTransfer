jQuery(document).ready(function() {
	 
	jQuery("#js-register-bt").on("click", function(event){
		event.preventDefault();
		jQuery('#js-banktransfer-modal').modal("show");
	});
	
	jQuery("#js-btbtn-yes").on("click", function(event){
		event.preventDefault();
			
		var data = {
			project_id: jQuery(this).data("project-id"),
			amount: jQuery(this).data("amount")
		};
		
		jQuery.ajax({
			url: "index.php?option=com_crowdfunding&task=payments.banktransfer&format=raw",
			type: "POST",
			data: data,
			dataType: "text json",
			cache: false,
			beforeSend: function(response) {
				
				// Display ajax loading image
				jQuery("#js-banktransfer-ajax-loading").show();
				
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