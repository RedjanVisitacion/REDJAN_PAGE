document.addEventListener("DOMContentLoaded", function () {
  // Show modal for name input
  const modal = document.getElementById("nameModal");
  modal.style.display = "flex";

  document.getElementById("submitName").addEventListener("click", function () {
    let recipientName = document.getElementById("nameInput").value.trim();
    
    if (recipientName === "") {
      alert("Please enter a valid name.");
    } else {
      document.getElementById("recipientName").innerText = recipientName;
      document.getElementById("date").innerText = new Date().toLocaleDateString();
      modal.style.display = "none"; // Hide modal
    }
  });

  // Download Certificate as PDF
  document.getElementById("downloadBtn").addEventListener("click", function () {
    const certificate = document.getElementById("certificate");
    
    const opt = {
      margin: 0,
      filename: 'Certificate_of_Remembrance.pdf',
      image: { type: 'jpeg', quality: 1 },
      html2canvas: { scale: 3, useCORS: true, backgroundColor: null },
      jsPDF: { unit: 'mm', format: 'a4', orientation: 'landscape' }
    };

    html2pdf().from(certificate).set(opt).save();
  });
});
