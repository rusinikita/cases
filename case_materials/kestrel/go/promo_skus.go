package paymentconsumer

// promoSKUs is the "special list" of the next-order discount campaign
// (MKT-2217). The list is owned by marketing and changes with a release.
var promoSKUs = map[string]struct{}{
	"GR-104522": {},
	"GR-104523": {},
	"GR-104871": {},
	"GR-105310": {},
	"GR-105311": {},
	"GR-107764": {},
	"DR-200418": {},
	"DR-200419": {},
	"DR-200977": {},
	"DR-201350": {},
	"DR-203006": {},
	"HH-310045": {},
	"HH-310046": {},
	"HH-310212": {},
	"HH-311890": {},
	"HH-312407": {},
	"HH-312408": {},
	"BT-450031": {},
	"BT-450032": {},
	"BT-450670": {},
	"BT-451128": {},
	"PT-520903": {},
	"PT-520904": {},
	"PT-521455": {},
	"PT-523310": {},
	"SN-600172": {},
	"SN-600173": {},
	"SN-600589": {},
	"SN-601244": {},
	"SN-602001": {},
	"FZ-710336": {},
	"FZ-710337": {},
	"FZ-712850": {},
	"KD-830019": {},
	"KD-830420": {},
	"KD-831777": {},
}

func hasPromoSKU(items []Item) bool {
	for _, it := range items {
		if _, ok := promoSKUs[it.SKU]; ok {
			return true
		}
	}
	return false
}
