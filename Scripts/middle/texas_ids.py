# Define input and output paths
input_path = r"C:\Users\daniu\OneDrive\Documents\GitHub\Kids-Over-Profits\Scripts\middle\texas_hhs.txt"
output_path = r"C:\Users\daniu\OneDrive\Documents\GitHub\Kids-Over-Profits\Scripts\middle\provider_ids.txt"

# Open input and output files
with open(input_path, 'r', encoding='utf-8') as infile, open(output_path, 'w', encoding='utf-8') as outfile:
    for line in infile:
        if "providerNum" in line:
            # Split on 'providerNum' and take everything after
            after = line.split("providerNum", 1)[1].strip()
            outfile.write(after + "\n")