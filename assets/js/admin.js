(function () {
	var copy = document.getElementById("cwv-copy-nginx");
	if (copy) {
		copy.addEventListener("click", function () {
			var area = document.getElementById("cwv-nginx");
			var label = copy.textContent;
			if (!area) {
				return;
			}
			var done = function () {
				copy.textContent = window.cwvSpeed && cwvSpeed.copied ? cwvSpeed.copied : "Copied";
				window.setTimeout(function () {
					copy.textContent = label;
				}, 1600);
			};
			if (navigator.clipboard && navigator.clipboard.writeText) {
				navigator.clipboard.writeText(area.value).then(done);
				return;
			}
			area.focus();
			area.select();
			document.execCommand("copy");
			done();
		});
	}

	var button = document.getElementById("cwv-convert");
	if (!button || typeof cwvSpeed === "undefined") {
		return;
	}

	button.addEventListener("click", function () {
		var offset = 0;
		var done = 0;
		var status = document.getElementById("cwv-convert-status");
		button.disabled = true;

		function step() {
			var data = new FormData();
			data.append("action", "cwv_speed_convert_images");
			data.append("offset", String(offset));
			data.append("_ajax_nonce", cwvSpeed.nonce);

			window.fetch(cwvSpeed.ajax, {
				method: "POST",
				credentials: "same-origin",
				body: data
			}).then(function (response) {
				return response.json();
			}).then(function (json) {
				if (!json || !json.success) {
					button.disabled = false;
					if (status) {
						status.textContent = (json && json.data) ? String(json.data) : "Conversion failed.";
					}
					return;
				}
				done += json.data.processed;
				offset = json.data.next;
				if (status) {
					status.textContent = "Converted " + done + " of " + json.data.total + " images.";
				}
				if (json.data.done) {
					button.disabled = false;
					return;
				}
				step();
			}).catch(function () {
				button.disabled = false;
				if (status) {
					status.textContent = "Conversion failed.";
				}
			});
		}

		step();
	});
}());
