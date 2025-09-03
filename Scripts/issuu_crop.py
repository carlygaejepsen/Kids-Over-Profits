from PIL import Image
import os

# Define crop box (left, top, right, bottom)
crop_box = (223, 13, 1698, 969)

# Input and output folders
input_folder = r"C:\Users\daniu\OneDrive\Desktop\Workflow Tools\screenshots"
output_folder = os.path.join(input_folder, "cropped")

# Create output folder if it doesn't exist
os.makedirs(output_folder, exist_ok=True)

# Loop through PNG files and crop
for filename in os.listdir(input_folder):
    if filename.lower().endswith(".png"):
        img_path = os.path.join(input_folder, filename)
        img = Image.open(img_path)
        cropped = img.crop(crop_box)
        cropped.save(os.path.join(output_folder, filename))