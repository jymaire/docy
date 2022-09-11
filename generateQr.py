import qrcode
from PIL import Image
def generateRandomQR():
    PREFIX = "P"
    for x in range(1, 9999):
        qr = qrcode.QRCode(version=1,
        error_correction=qrcode.constants.ERROR_CORRECT_H,
        box_size=10,
        border=4,
        )
        if (x <10):
            qr.add_data(PREFIX + "000" + str(x))
        elif (x <100):
            qr.add_data(PREFIX + "00" + str(x))
        elif (x <1000):
            qr.add_data(PREFIX + "0" + str(x))
        else:
            qr.add_data(PREFIX + str(x))
        qr.make(fit=True)
        img = qr.make_image()
        if (x <10):
            img.save(PREFIX + "000" + str(x)+ ".png")
        elif (x <100):
            img.save(PREFIX + "00" + str(x)+ ".png")
        elif (x <1000):
            img.save(PREFIX + "0" + str(x)+ ".png")
        else:
            img.save(PREFIX + str(x)+ ".png")

generateRandomQR()

# pip install qrcode[pil]
# pip install Image
